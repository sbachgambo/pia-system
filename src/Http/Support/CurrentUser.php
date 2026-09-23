<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Auth\AuthenticatedUser;
use App\Auth\UserRepository;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Support\ApiException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the request's authenticated user. The JWT only carries the uuid;
 * when a service needs the internal `id` (e.g. to set a FK like
 * `inspection_requests.requested_by`) this does the single lookup.
 */
final class CurrentUser
{
    public static function fromRequest(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(JwtAuthMiddleware::ATTRIBUTE);

        if (!$user instanceof AuthenticatedUser) {
            // Wiring mistake: a route used this without JwtAuthMiddleware.
            throw new ApiException(401, 'unauthorized', 'Authentication required.');
        }

        return $user;
    }

    public static function id(ServerRequestInterface $request, UserRepository $users): int
    {
        $uuid = self::fromRequest($request)->uuid;
        $row = $users->findByUuid($uuid);

        if ($row === null) {
            throw new ApiException(401, 'unauthorized', 'Your account no longer exists.');
        }

        return (int) $row['id'];
    }
}
