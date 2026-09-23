<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Argon2id hashing for both `password_hash` and `pin_hash` (brief §7).
 *
 * Cost parameters come from config (env-tunable) so they can be raised as
 * hosting allows without touching code. `needsRehash()` lets the login path
 * transparently upgrade a stored hash when the parameters change.
 */
final class PasswordHasher
{
    /** @param array{memory_cost:int,time_cost:int,threads:int} $options */
    public function __construct(private readonly array $options)
    {
    }

    public function hash(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(string $plaintext, string $hash): bool
    {
        if ($hash === '') {
            // Still spend time so callers can't distinguish "no hash on file"
            // from "wrong value" by timing.
            password_verify($plaintext, '$argon2id$v=19$m=65536,t=4,p=1$AAAAAAAAAAAAAAAAAAAAAA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
            return false;
        }

        return password_verify($plaintext, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
