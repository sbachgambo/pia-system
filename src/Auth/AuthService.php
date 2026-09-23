<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;

/**
 * Orchestrates login and refresh. Controllers stay thin; this holds the rules:
 *
 *  - constant-ish work on unknown email (dummy verify) so response timing does
 *    not reveal whether an address is registered,
 *  - transparent Argon2id rehash when cost parameters change,
 *  - `last_login_at` stamped on every successful online login (drives D4),
 *  - suspended accounts are refused and their refresh families revoked.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly AccessTokenService $accessTokens,
        private readonly RefreshTokenService $refreshTokens,
        private readonly OfflineSession $offline,
    ) {
    }

    /** @return array<string,mixed> the token bundle */
    public function login(string $email, string $password, ?string $deviceId): array
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));

        if ($user === null) {
            // Burn comparable time, then fail the same way a bad password does.
            $this->hasher->verify($password, '');
            throw new InvalidCredentialsException();
        }

        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            throw new InvalidCredentialsException();
        }

        if ($user['status'] === 'suspended') {
            throw new AccountSuspendedException();
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], $this->hasher->hash($password));
        }

        $loginAt = new DateTimeImmutable();
        $this->users->updateLastLogin((int) $user['id'], $loginAt);

        $refresh = $this->refreshTokens->issueForLogin((int) $user['id'], $loginAt, $deviceId);

        return $this->bundle($user, $refresh['token'], $refresh['expires_at'], $loginAt);
    }

    /** @return array<string,mixed> the token bundle */
    public function refresh(string $rawRefreshToken, ?string $deviceId): array
    {
        $rotated = $this->refreshTokens->rotate($rawRefreshToken, $deviceId);

        $user = $this->users->findById($rotated['user_id']);
        if ($user === null) {
            throw new InvalidTokenException('Account no longer exists.');
        }

        if ($user['status'] === 'suspended') {
            $this->refreshTokens->revokeAllForUser((int) $user['id']);
            throw new AccountSuspendedException();
        }

        return $this->bundle($user, $rotated['token'], $rotated['expires_at']);
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function bundle(
        array $user,
        string $refreshToken,
        DateTimeImmutable $refreshExpiresAt,
        ?DateTimeImmutable $lastLoginAt = null,
    ): array {
        $access = $this->accessTokens->issue(
            (string) $user['uuid'],
            (string) $user['role'],
            $user['zone'] !== null ? (string) $user['zone'] : null,
        );

        return [
            'token_type'               => 'Bearer',
            'access_token'             => $access['token'],
            'access_token_expires_at'  => $access['expires_at'],
            'expires_in'               => $access['expires_in'],
            'refresh_token'            => $refreshToken,
            'refresh_token_expires_at' => $refreshExpiresAt->format(DATE_ATOM),
            'user' => [
                'uuid'      => (string) $user['uuid'],
                'full_name' => (string) $user['full_name'],
                'email'     => (string) $user['email'],
                'role'      => (string) $user['role'],
                'zone'      => $user['zone'] !== null ? (string) $user['zone'] : null,
            ],
            // Offline half of the session for the PWA's auth_cache (brief §6).
            'offline' => $this->offline->describe($user, $lastLoginAt),
        ];
    }
}
