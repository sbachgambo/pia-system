<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Rotating refresh tokens with theft detection — the server half of D4.
 *
 * The raw token is opaque random bytes (base64url). Only its SHA-256 hash is
 * stored. On every use the token is rotated: the presented one is marked used
 * and a fresh one is issued in the same `family`. Key rules:
 *
 *  - A family descends from ONE online login (`login_at`). Every token in the
 *    family expires at `login_at + offline_reauth_days` — so refreshing can
 *    NEVER push the session past the D4 7-day limit.
 *  - Presenting an already-used token = theft signal → the whole family is
 *    revoked and the caller gets `invalid_token`.
 *  - Past the 7-day cap → `reauth_required` (client must log in online).
 */
final class RefreshTokenService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $reauthDays,
    ) {
    }

    /**
     * Start a new token family for a fresh online login.
     *
     * @return array{token:string, expires_at:DateTimeImmutable, family_id:string}
     */
    public function issueForLogin(int $userId, DateTimeImmutable $loginAt, ?string $deviceId): array
    {
        $familyId  = Uuid::uuid4()->toString();
        $raw       = $this->randomToken();
        $expiresAt = $loginAt->modify("+{$this->reauthDays} days");

        $this->insert($userId, $familyId, $raw, $loginAt, $loginAt, $expiresAt, $deviceId);

        return ['token' => $raw, 'expires_at' => $expiresAt, 'family_id' => $familyId];
    }

    /**
     * Exchange a valid refresh token for a new one.
     *
     * @return array{user_id:int, token:string, expires_at:DateTimeImmutable, login_at:DateTimeImmutable}
     * @throws InvalidTokenException|ReauthRequiredException
     */
    public function rotate(string $rawToken, ?string $deviceId): array
    {
        $now  = new DateTimeImmutable();
        $hash = $this->hash($rawToken);

        $row = $this->findByHash($hash);
        if ($row === null) {
            throw new InvalidTokenException('Unknown refresh token.');
        }

        $familyId = (string) $row['family_id'];
        $loginAt  = new DateTimeImmutable((string) $row['login_at']);

        if ($row['revoked_at'] !== null) {
            throw new InvalidTokenException('This session has been revoked.');
        }

        if ($row['used_at'] !== null) {
            // The token was already rotated once. Someone is replaying it.
            $this->revokeFamily($familyId);
            throw new InvalidTokenException('Refresh token reuse detected; session revoked.');
        }

        if ($now > $loginAt->modify("+{$this->reauthDays} days")) {
            $this->revokeFamily($familyId);
            throw new ReauthRequiredException();
        }

        // Atomically claim this token. If another concurrent request already
        // did, rowCount() is 0 and we treat it as reuse.
        $newRaw  = $this->randomToken();
        $newHash = $this->hash($newRaw);

        // NOTE: native prepares (emulation is off, §7) do not allow a named
        // placeholder to appear twice, so row-audit columns use UTC_TIMESTAMP()
        // in SQL rather than a repeated :now bind.
        $claim = $this->pdo->prepare(
            'UPDATE refresh_tokens
                SET used_at = :used_at, replaced_by = :newhash, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL'
        );
        $claim->execute([
            'used_at' => $now->format('Y-m-d H:i:s'),
            'newhash' => $newHash,
            'id'      => (int) $row['id'],
        ]);

        if ($claim->rowCount() === 0) {
            $this->revokeFamily($familyId);
            throw new InvalidTokenException('Refresh token reuse detected; session revoked.');
        }

        $expiresAt = $loginAt->modify("+{$this->reauthDays} days");
        $this->insert((int) $row['user_id'], $familyId, $newRaw, $loginAt, $now, $expiresAt, $deviceId);

        return [
            'user_id'    => (int) $row['user_id'],
            'token'      => $newRaw,
            'expires_at' => $expiresAt,
            'login_at'   => $loginAt,
        ];
    }

    public function revokeFamily(string $familyId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = :revoked_at, updated_at = UTC_TIMESTAMP()
              WHERE family_id = :fam AND revoked_at IS NULL'
        );
        $stmt->execute(['revoked_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'fam' => $familyId]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = :revoked_at, updated_at = UTC_TIMESTAMP()
              WHERE user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute(['revoked_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'uid' => $userId]);
    }

    /** For the nightly cron (Phase 7). */
    public function purgeExpired(DateTimeImmutable $olderThan): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM refresh_tokens WHERE expires_at < :cutoff');
        $stmt->execute(['cutoff' => $olderThan->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }

    // --- internals -----------------------------------------------------

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** @return array<string,mixed>|null */
    private function findByHash(string $hash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, family_id, login_at, issued_at, expires_at, used_at, revoked_at
               FROM refresh_tokens WHERE token_hash = :h LIMIT 1'
        );
        $stmt->execute(['h' => $hash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function insert(
        int $userId,
        string $familyId,
        string $rawToken,
        DateTimeImmutable $loginAt,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        ?string $deviceId,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO refresh_tokens
                (user_id, family_id, token_hash, login_at, issued_at, expires_at, device_id, created_at, updated_at)
             VALUES
                (:uid, :fam, :hash, :login_at, :issued_at, :expires_at, :device_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uid'        => $userId,
            'fam'        => $familyId,
            'hash'       => $this->hash($rawToken),
            'login_at'   => $loginAt->format('Y-m-d H:i:s'),
            'issued_at'  => $issuedAt->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'device_id'  => $deviceId,
        ]);
    }
}
