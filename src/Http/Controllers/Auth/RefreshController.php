<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\AuthService;
use App\Http\Support\ClientContext;
use App\Http\Support\Input;
use App\Http\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/auth/refresh  — brief §5, used by the D4 flow.
 * Body: { "refresh_token": "..." }
 *
 * Rotates the refresh token and returns a fresh bundle. Fails with:
 *   invalid_token   — unknown / already-used / revoked token (family killed)
 *   reauth_required — the chain has passed its 7-day cap; log in online
 */
final class RefreshController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromRequest($request);

        $bundle = $this->auth->refresh(
            rawRefreshToken: $input->requiredString('refresh_token', 512),
            deviceId: ClientContext::deviceId($request),
        );

        return Json::write($response, $bundle, 200);
    }
}
