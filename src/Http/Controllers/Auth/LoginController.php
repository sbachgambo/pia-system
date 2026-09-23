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
 * POST /api/auth/login  — brief §5.
 * Body: { "email": "...", "password": "..." }
 * Returns the token bundle (access + refresh + user summary).
 * Rate-limited by AuthRateLimitMiddleware.
 */
final class LoginController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromRequest($request);

        $bundle = $this->auth->login(
            email: $input->requiredString('email', 190),
            password: $input->requiredString('password', 4096),
            deviceId: ClientContext::deviceId($request),
        );

        return Json::write($response, $bundle, 200);
    }
}
