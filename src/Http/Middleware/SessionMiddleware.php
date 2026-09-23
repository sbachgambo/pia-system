<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Native PHP session for the server-rendered console (Q4: cookie session +
 * CSRF for the console, JWT for the API). Only mounted on `/console/*`.
 *
 * Hardened cookie: HttpOnly, SameSite=Lax, and Secure in non-local
 * environments. The session bag is exposed to controllers as the
 * `session` request attribute (a SessionStore over $_SESSION).
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'session';

    /**
     * @param string $name cookie name
     * @param string $path cookie path
     */
    public function __construct(
        private readonly bool $secureCookie,
        private readonly string $name = 'pia_console',
        private readonly string $path = '/',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => $this->path,
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $this->secureCookie,
            ]);
            session_name($this->name);
            session_start();
        }

        $request = $request->withAttribute(self::ATTRIBUTE, SessionStore::overSuperglobal());

        try {
            return $handler->handle($request);
        } finally {
            // Persist and release the lock before the response is emitted.
            session_write_close();
        }
    }
}
