<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Assigns a stable id to each request:
 *  - honours an inbound `X-Request-Id` if the caller supplied a sane one
 *    (the PWA sets this so a queued sync can be traced end to end),
 *  - otherwise mints a UUIDv4,
 *  - exposes it as a request attribute (`request_id`) for controllers/services,
 *  - echoes it back in the `X-Request-Id` response header.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'request_id';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $inbound = $request->getHeaderLine('X-Request-Id');
        $requestId = $this->isAcceptable($inbound) ? $inbound : Uuid::uuid4()->toString();

        $request = $request->withAttribute(self::ATTRIBUTE, $requestId);

        $response = $handler->handle($request);

        return $response->withHeader('X-Request-Id', $requestId);
    }

    private function isAcceptable(string $value): bool
    {
        // Printable ASCII, bounded length — do not reflect arbitrary junk into logs/headers.
        return $value !== ''
            && strlen($value) <= 128
            && preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1;
    }
}
