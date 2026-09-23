<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\AuthenticatedUser;
use App\Auth\OfflineSession;
use App\Auth\UserRepository;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpUnauthorizedException;

/**
 * GET /api/me  — not in the §5 table (DEV-6, small additive helper).
 *
 * Lets the PWA/console confirm a token is still valid and read the current
 * user's profile without decoding the JWT themselves. Behind JwtAuthMiddleware.
 */
final class MeController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly OfflineSession $offline,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var AuthenticatedUser|null $auth */
        $auth = $request->getAttribute(JwtAuthMiddleware::ATTRIBUTE);

        if (!$auth instanceof AuthenticatedUser) {
            throw new HttpUnauthorizedException($request);
        }

        $user = $this->users->findByUuid($auth->uuid);

        if ($user === null) {
            throw new HttpUnauthorizedException($request);
        }

        return Json::write($response, [
            'uuid'          => (string) $user['uuid'],
            'full_name'     => (string) $user['full_name'],
            'email'         => (string) $user['email'],
            'role'          => (string) $user['role'],
            'zone'          => $user['zone'] !== null ? (string) $user['zone'] : null,
            'status'        => (string) $user['status'],
            'last_login_at' => $user['last_login_at'] !== null
                ? (new \DateTimeImmutable((string) $user['last_login_at']))->format(DATE_ATOM)
                : null,
            'offline' => $this->offline->describe($user),
        ]);
    }
}
