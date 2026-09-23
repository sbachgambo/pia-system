<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\Csrf;
use App\Http\Support\SessionStore;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Synchroniser-token CSRF check for the console (brief §7). Mounted inside
 * SessionMiddleware. Safe methods pass through untouched; state-changing
 * methods must carry a matching token in the `_csrf` form field or the
 * `X-CSRF-Token` header.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'csrf';

    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $csrf = new Csrf($session instanceof SessionStore ? $session : new SessionStore());

        // Make the token available to controllers/templates.
        $request = $request->withAttribute(self::ATTRIBUTE, $csrf);

        if (!in_array(strtoupper($request->getMethod()), self::SAFE, true)) {
            $body = $request->getParsedBody();
            $candidate = is_array($body) && isset($body['_csrf']) && is_string($body['_csrf'])
                ? $body['_csrf']
                : $request->getHeaderLine('X-CSRF-Token');

            if (!$csrf->isValid($candidate)) {
                throw new ApiException(403, 'csrf_failed', 'This form has expired. Please reload and try again.');
            }
        }

        return $handler->handle($request);
    }
}
