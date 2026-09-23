<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\RateLimiter;
use App\Http\Support\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies the fixed-window rate limiter to the routes it is mounted on
 * (the `/api/auth/*` group — brief §5/§7). Keyed by client IP + the request
 * path, so hammering /login and /refresh are counted separately.
 *
 * Throws 429 (with Retry-After) via RateLimitedException when the bucket is
 * over its limit; JsonErrorHandler renders it.
 */
final class AuthRateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $bucket = sprintf(
            'auth:%s:%s',
            $request->getUri()->getPath(),
            ClientContext::ip($request),
        );

        $this->limiter->enforce($bucket);

        return $handler->handle($request);
    }
}
