<?php

declare(strict_types=1);

namespace App\Users;

use DateTimeImmutable;

/**
 * Shapes a raw `users` row for the console — never includes password_hash /
 * pin_hash (the repository doesn't even select them for this path).
 */
final class UserRepresentation
{
    /** @param array<string,mixed> $row */
    public static function admin(array $row): array
    {
        return [
            'uuid'          => (string) $row['uuid'],
            'full_name'     => (string) $row['full_name'],
            'email'         => (string) $row['email'],
            'phone'         => $row['phone'] !== null ? (string) $row['phone'] : null,
            'role'          => (string) $row['role'],
            'zone'          => $row['zone'] !== null ? (string) $row['zone'] : null,
            'status'        => (string) $row['status'],
            'receive_digest' => (bool) ($row['receive_digest'] ?? true),
            'two_factor'   => !empty($row['totp_enabled_at']),
            'last_login_at' => self::ts($row['last_login_at']),
            'created_at'    => self::ts($row['created_at']),
            'updated_at'    => self::ts($row['updated_at']),
        ];
    }

    private static function ts(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
