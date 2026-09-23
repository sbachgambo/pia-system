<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Describes the offline half of a session for the PWA's `auth_cache`
 * (brief §6): whether an offline PIN is set, the Argon2id `pin_hash` the
 * device verifies that PIN against locally, and the hard deadline by which
 * the inspector must log in online again (D4 — every `reauth_days` days).
 *
 * Handing the `pin_hash` to the client is deliberate and specified by §6
 * ("`pin_hash` for local offline verification (never the plaintext PIN)").
 * It only ever travels over an authenticated Bearer channel to the account
 * holder, and Argon2id keeps an offline guess expensive. See DEV-11.
 */
final class OfflineSession
{
    public function __construct(private readonly int $reauthDays)
    {
    }

    /**
     * @param array<string,mixed> $user a `users` row (must include pin_hash, last_login_at)
     * @param DateTimeImmutable|null $lastLoginAt overrides the row's stale
     *        last_login_at on the login path, where it was just re-stamped
     * @return array{pin_set:bool, pin_hash:?string, reauth_deadline:?string, reauth_days:int}
     */
    public function describe(array $user, ?DateTimeImmutable $lastLoginAt = null): array
    {
        $pinHash = isset($user['pin_hash']) && (string) $user['pin_hash'] !== ''
            ? (string) $user['pin_hash']
            : null;

        $anchor = $lastLoginAt;
        if ($anchor === null && isset($user['last_login_at']) && $user['last_login_at'] !== null) {
            $anchor = new DateTimeImmutable((string) $user['last_login_at'], new DateTimeZone('UTC'));
        }

        return [
            'pin_set'         => $pinHash !== null,
            'pin_hash'        => $pinHash,
            'reauth_deadline' => $anchor?->modify("+{$this->reauthDays} days")->format(DATE_ATOM),
            'reauth_days'     => $this->reauthDays,
        ];
    }
}
