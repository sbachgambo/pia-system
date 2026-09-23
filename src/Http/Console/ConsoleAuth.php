<?php

declare(strict_types=1);

namespace App\Http\Console;

use App\Auth\AccountSuspendedException;
use App\Auth\InvalidCredentialsException;
use App\Auth\PasswordHasher;
use App\Auth\UserRepository;
use App\Support\ApiException;
use DateTimeImmutable;

/**
 * Password authentication for the server-rendered console. Unlike the API's
 * AuthService this issues no tokens — the caller drops the returned user's
 * uuid into the session. Console access is limited to office roles.
 */
final class ConsoleAuth
{
    // `board` (Board of Directors) signs in too, read-only: see BoardReadOnlyMiddleware.
    public const ROLES = ['office_reviewer', 'admin', 'super_admin', 'board'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /**
     * @return array<string,mixed> the user row on success
     * @throws InvalidCredentialsException|AccountSuspendedException|ApiException
     */
    public function attempt(string $email, string $password): array
    {
        $user = $this->users->findByEmail(strtolower(trim($email)));

        if ($user === null) {
            $this->hasher->verify($password, ''); // timing parity
            throw new InvalidCredentialsException();
        }

        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            throw new InvalidCredentialsException();
        }

        if ($user['status'] === 'suspended') {
            throw new AccountSuspendedException();
        }

        if (!in_array($user['role'], self::ROLES, true)) {
            throw new ApiException(403, 'forbidden', 'This account cannot use the office console.');
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], $this->hasher->hash($password));
        }

        // An account with a second factor isn't signed in yet — its last-login
        // time is stamped by the two-factor step once the code checks out, so a
        // stolen password alone never shows up as a sign-in.
        if (empty($user['totp_enabled_at'])) {
            $this->users->updateLastLogin((int) $user['id'], new DateTimeImmutable());
        }

        return $user;
    }
}
