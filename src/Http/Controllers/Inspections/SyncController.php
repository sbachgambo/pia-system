<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inspections;

use App\Auth\UserRepository;
use App\Http\Support\ClientContext;
use App\Http\Support\CurrentUser;
use App\Http\Support\Json;
use App\Inspections\SyncService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/inspections/sync — batch upload of field-captured inspections
 * (brief §5). Idempotent on inspection uuid. JWT + `inspector` role.
 */
final class SyncController
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiException(422, 'invalid_body', 'Request body must be a JSON object.');
        }

        $result = $this->sync->sync(
            $body,
            CurrentUser::id($request, $this->users),
            ClientContext::deviceId($request),
        );

        // 207-ish semantics, but keep it a plain 200 with per-record results.
        return Json::write($response, $result, 200);
    }
}
