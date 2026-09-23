<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AuthenticatedUser;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Restricts a route/group to a set of roles. Runs INSIDE JwtAuthMiddleware, so
 * it can rely on the `auth_user` attribute being present; if it is not, that is
 * a wiring mistake and we fail closed with 403.
 *
 * Construct with the allowed roles:
 *   $app->group(...)->add(new RoleMiddleware(['office_reviewer','admin','super_admin']))
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedRoles */
    public function __construct(private readonly array $allowedRoles)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(JwtAuthMiddleware::ATTRIBUTE);

        if (!$user instanceof AuthenticatedUser || !$user->hasAnyRole($this->allowedRoles)) {
            throw new ApiException(403, 'forbidden', 'You do not have access to this resource.');
        }

        return $handler->handle($request);
    }
}
