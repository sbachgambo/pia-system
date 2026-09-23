<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

/**
 * Read/update access to `users` for authentication. Every query is a prepared
 * statement (brief §7). Returns plain associative arrays — no ORM.
 */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, uuid, full_name, email, password_hash, pin_hash, role, zone, status, last_login_at, sessions_valid_after, totp_enabled_at
               FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, uuid, full_name, email, password_hash, pin_hash, role, zone, status, last_login_at, sessions_valid_after, totp_enabled_at
               FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, uuid, full_name, email, password_hash, pin_hash, role, zone, status, last_login_at, sessions_valid_after, totp_enabled_at
               FROM users WHERE uuid = :uuid LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updateLastLogin(int $userId, \DateTimeImmutable $when): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = :when WHERE id = :id');
        $stmt->execute(['when' => $when->format('Y-m-d H:i:s'), 'id' => $userId]);
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
        $stmt->execute(['h' => $hash, 'id' => $userId]);
    }

    /** Set (or, with null, clear) the offline PIN hash (D4 / Phase 8). */
    public function updatePinHash(int $userId, ?string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET pin_hash = :h WHERE id = :id');
        $stmt->execute(['h' => $hash, 'id' => $userId]);
    }
}
