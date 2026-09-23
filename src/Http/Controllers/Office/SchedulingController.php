<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office;

use App\Http\Support\Input;
use App\Http\Support\Json;
use App\Inspections\SchedulingService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/office/inspection-requests/{uuid}/schedule — assign a pending /
 * scheduled request to an inspector, creating the `inspections` row (A18).
 * JWT + office roles.
 *
 * Body: { inspector_uuid, location_type, location_detail, scheduled_at? }
 */
final class SchedulingController
{
    public function __construct(private readonly SchedulingService $scheduling)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write(
            $response,
            $this->scheduling->schedule((string) $args['uuid'], Input::fromRequest($request)),
            201,
        );
    }
}
