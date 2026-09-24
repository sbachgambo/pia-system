<?php

declare(strict_types=1);

namespace App\Inspections;

use App\Auth\UserRepository;
use App\Http\Support\Input;
use App\Office\InspectionRequestRepository;
use App\Office\NotFoundException;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;

/**
 * Office action: turn a pending/scheduled inspection request into a concrete
 * `inspections` row assigned to a named inspector (decision Q12 / A18 —
 * "Model A": the office creates the inspection; the field device syncs
 * updates to it).
 *
 * The inspection's uuid is generated here (a v4, not a DB sequence) so the
 * later field sync is idempotent against a row that already exists.
 */
final class SchedulingService
{
    private const LOCATION_TYPES = ['factory', 'warehouse', 'port', 'other'];

    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly InspectionRequestRepository $requests,
        private readonly UserRepository $users,
    ) {
    }

    /** @return array<string,mixed> the created inspection */
    public function schedule(string $requestUuid, Input $in): array
    {
        $request = $this->requests->findByUuid($requestUuid) ?? throw new NotFoundException('Inspection request');

        if (!in_array($request['status'], ['pending', 'scheduled'], true)) {
            throw new ApiException(
                422,
                'invalid_transition',
                "A {$request['status']} request cannot be scheduled.",
            );
        }

        $inspectorUuid = $in->requiredString('inspector_uuid', 36);
        $inspector = $this->users->findByUuid($inspectorUuid)
            ?? throw new ApiException(422, 'validation_failed', 'Unknown inspector_uuid.', ['field' => 'inspector_uuid']);

        if ($inspector['role'] !== 'inspector') {
            throw new ApiException(422, 'validation_failed', 'That user is not an inspector.', ['field' => 'inspector_uuid']);
        }
        if ($inspector['status'] !== 'active') {
            throw new ApiException(422, 'validation_failed', 'That inspector is not active.', ['field' => 'inspector_uuid']);
        }

        $scheduledAt = $in->optionalDateTime('scheduled_at') ?? $this->now();
        $locationType = $in->requiredEnum('location_type', self::LOCATION_TYPES);
        $locationDetail = $in->requiredString('location_detail', 255);

        $inspection = $this->inspections->createScheduled(
            Uuid::uuid4()->toString(),
            (int) $request['id'],
            (int) $inspector['id'],
            $scheduledAt,
            $locationType,
            $locationDetail,
        );

        // Move the request forward if it was still pending. Conditional on
        // that same status, so two officers scheduling at once cannot both win.
        if ($request['status'] === 'pending') {
            $this->requests->updateStatus((int) $request['id'], 'scheduled', 'pending');
        }

        return Representation::inspection($inspection);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
