<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inspections;

use App\Auth\UserRepository;
use App\Http\Support\CurrentUser;
use App\Http\Support\Json;
use App\Inspections\AssignmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/inspections/assigned — the signed-in inspector's working set for
 * offline caching (brief §5). JWT + `inspector` role.
 */
final class AssignedController
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $inspectorId = CurrentUser::id($request, $this->users);

        return Json::write($response, $this->assignments->forInspector($inspectorId));
    }
}
