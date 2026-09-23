<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Board of Directors accounts are read-only, everywhere in the console.
 *
 * One gate for the whole signed-in console rather than a check in each
 * controller: any request that could change something — every non-GET — is
 * refused for a board user, so a write action added later is covered without
 * anyone remembering to exclude the board. The only writes a board member may
 * make are to their own account (password, two-factor) and signing out.
 *
 * The "new record" pages are refused too: they are only forms, and a form the
 * user can never submit is a dead end.
 *
 * Runs INSIDE ConsoleAuthMiddleware, so `console_user` is present.
 */
final class BoardReadOnlyMiddleware implements MiddlewareInterface
{
    public const ROLE = 'board';

    /** Writes a board member may still make: their own account, and signing out. */
    private const ALLOWED_WRITES = ['#^/console/account(/|$)#', '#^/console/logout$#'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        if (!is_array($user) || ($user['role'] ?? null) !== self::ROLE) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        $write = !in_array($method, ['GET', 'HEAD'], true);
        if ($write) {
            foreach (self::ALLOWED_WRITES as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return $handler->handle($request);
                }
            }
        }

        if ($write || preg_match('#/new$#', $path) === 1) {
            /** @var \App\Http\Support\SessionStore|null $session */
            $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
            if ($session !== null) {
                $flashes = $session->get('_flash', []);
                $flashes[] = ['type' => 'error', 'message' => 'Board access is read-only: records can be viewed and downloaded, not changed.'];
                $session->set('_flash', $flashes);
            }

            // Back to where they were when that is one of our own pages.
            $back = $request->getHeaderLine('Referer');
            $backPath = $back !== '' ? (string) parse_url($back, PHP_URL_PATH) : '';
            $to = str_starts_with($backPath, '/console') && preg_match('#/new$#', $backPath) !== 1 ? $backPath : '/console';

            return (new ResponseFactory())->createResponse(303)->withHeader('Location', $to);
        }

        return $handler->handle($request);
    }
}
