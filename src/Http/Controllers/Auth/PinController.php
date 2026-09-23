<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\OfflineSession;
use App\Auth\PinService;
use App\Auth\UserRepository;
use App\Http\Support\CurrentUser;
use App\Http\Support\Input;
use App\Http\Support\Json;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST/DELETE /api/me/pin — manage the caller's offline unlock PIN (D4,
 * brief §6). Not in the §5 table (DEV-11, additive). Behind JwtAuthMiddleware.
 *
 * The response echoes the fresh `offline` block so the PWA can drop it
 * straight into `auth_cache` without a second round trip.
 */
final class PinController
{
    public function __construct(
        private readonly PinService $pins,
        private readonly UserRepository $users,
        private readonly OfflineSession $offline,
    ) {
    }

    public function set(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromRequest($request);
        $user  = $this->currentRow($request);

        $this->pins->set(
            $user,
            $input->requiredString('current_password', 4096),
            $input->requiredString('pin', 32),
        );

        return $this->offlineBlock($response, (string) $user['uuid']);
    }

    public function clear(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->currentRow($request);

        $this->pins->clear((int) $user['id']);

        return $this->offlineBlock($response, (string) $user['uuid']);
    }

    /** @return array<string,mixed> */
    private function currentRow(ServerRequestInterface $request): array
    {
        $row = $this->users->findByUuid(CurrentUser::fromRequest($request)->uuid);

        if ($row === null) {
            throw new ApiException(401, 'unauthorized', 'Your account no longer exists.');
        }

        return $row;
    }

    private function offlineBlock(ResponseInterface $response, string $uuid): ResponseInterface
    {
        $fresh = $this->users->findByUuid($uuid);

        return Json::write($response, [
            'offline' => $this->offline->describe($fresh ?? []),
        ]);
    }
}
