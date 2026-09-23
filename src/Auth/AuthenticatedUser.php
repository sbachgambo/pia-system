<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The identity extracted from a valid access token, attached to the request by
 * JwtAuthMiddleware. Carries only what the token holds — no DB row. Controllers
 * that need the full user record look it up by uuid.
 */
final class AuthenticatedUser
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $role,
        public readonly ?string $zone,
        public readonly string $tokenId,
    ) {
    }

    /** @param list<string> $roles */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
