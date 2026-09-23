<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Route-level role gate for console page groups (Phase 9 hardening) — the
 * console equivalent of RoleMiddleware, mirrored at the routing layer instead
 * of left as a per-action check inside each controller (which is how
 * Settings/Compliance/StatutoryReturns started out in Phases 6/7: correct,
 * but one missed call away from a hole the next time an admin-only action is
 * added). Runs INSIDE ConsoleAuthMiddleware, so `console_user` is present.
 *
 * On failure: flash + redirect to the dashboard (not a JSON 403 — this is a
 * browser page), same UX the controllers' own checks used to produce.
 *
 *   $app->group(...)->add(new ConsoleRoleMiddleware(['admin','super_admin']))
 */
final class ConsoleRoleMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedRoles */
    public function __construct(private readonly array $allowedRoles)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $role = is_array($user) ? ($user['role'] ?? null) : null;

        if (!in_array($role, $this->allowedRoles, true)) {
            /** @var \App\Http\Support\SessionStore|null $session */
            $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
            if ($session !== null) {
                $flashes = $session->get('_flash', []);
                $flashes[] = ['type' => 'error', 'message' => 'Only admins can access this page.'];
                $session->set('_flash', $flashes);
            }

            return (new ResponseFactory())->createResponse(302)->withHeader('Location', '/console');
        }

        return $handler->handle($request);
    }
}
