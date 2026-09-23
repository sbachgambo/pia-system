<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AccessTokenService;
use App\Auth\AuthenticatedUser;
use App\Auth\InvalidTokenException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gate for JWT-protected routes (brief §5: everything except /auth/login).
 *
 * Expects `Authorization: Bearer <token>`. On success it attaches an
 * AuthenticatedUser to the request as the `auth_user` attribute. On any
 * failure it throws `invalid_token` (401) — rendered by JsonErrorHandler.
 */
final class JwtAuthMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'auth_user';

    public function __construct(private readonly AccessTokenService $accessTokens)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw new InvalidTokenException('Missing bearer token.');
        }

        $claims = $this->accessTokens->verify($m[1]); // throws InvalidTokenException

        $user = new AuthenticatedUser(
            uuid: $claims['uuid'],
            role: $claims['role'],
            zone: $claims['zone'],
            tokenId: $claims['jti'],
        );

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $user));
    }
}
