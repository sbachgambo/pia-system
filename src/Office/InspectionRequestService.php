<?php

declare(strict_types=1);

namespace App\Office;

use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;

/**
 * Business rules for `inspection_requests`.
 *
 *  - notice_deadline = requested_at + notice_window_hours   (assumption A14 / Q9)
 *  - status state machine (D-style guard, office side):
 *        pending   -> scheduled | cancelled
 *        scheduled -> completed | cancelled
 *        completed -> (terminal)
 *        cancelled -> (terminal)
 */
final class InspectionRequestService
{
    private const TRANSITIONS = [
        'pending'   => ['scheduled', 'cancelled'],
        'scheduled' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly InspectionRequestRepository $requests,
        private readonly ConsignmentRepository $consignments,
        private readonly int $noticeWindowHours,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function create(Input $in, int $requestedByUserId): array
    {
        $consignmentUuid = $in->requiredString('consignment_uuid', 36);
        $consignmentId = $this->consignments->findIdByUuid($consignmentUuid)
            ?? throw new ApiException(422, 'validation_failed', 'Unknown consignment_uuid.', ['field' => 'consignment_uuid']);

        $requestedAt = $in->optionalDateTime('requested_at') ?? $this->now();
        $deadline = $this->deadlineFor($requestedAt);

        return Representation::inspectionRequest(
            $this->requests->create(
                Uuid::uuid4()->toString(),
                $consignmentId,
                $requestedByUserId,
                $requestedAt,
                $deadline,
            ),
        );
    }

    /**
     * Reschedule: change `requested_at` and recompute `notice_deadline`.
     * Only allowed while the request is still open.
     *
     * @return array<string,mixed>
     */
    public function reschedule(string $uuid, Input $in): array
    {
        $row = $this->requests->findByUuid($uuid) ?? throw new NotFoundException('Inspection request');

        if (!in_array($row['status'], ['pending', 'scheduled'], true)) {
            throw new ApiException(422, 'invalid_transition', 'A completed or cancelled request cannot be rescheduled.');
        }

        $requestedAt = $in->optionalDateTime('requested_at')
            ?? throw new ApiException(422, 'validation_failed', '`requested_at` is required.', ['field' => 'requested_at']);

        return Representation::inspectionRequest(
            $this->requests->updateSchedule((int) $row['id'], $requestedAt, $this->deadlineFor($requestedAt)),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function transition(string $uuid, Input $in): array
    {
        $row = $this->requests->findByUuid($uuid) ?? throw new NotFoundException('Inspection request');
        $from = (string) $row['status'];
        $to = $in->requiredEnum('status', ['pending', 'scheduled', 'completed', 'cancelled']);

        if ($to === $from) {
            return Representation::inspectionRequest($row); // no-op, idempotent
        }

        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new ApiException(
                422,
                'invalid_transition',
                "Cannot move an inspection request from `{$from}` to `{$to}`.",
                ['from' => $from, 'to' => $to, 'allowed' => self::TRANSITIONS[$from] ?? []],
            );
        }

        // Pass the status we just validated, so a request another user has
        // already moved on is refused rather than overwritten.
        return Representation::inspectionRequest($this->requests->updateStatus((int) $row['id'], $to, $from));
    }

    /** @return array<string,mixed> */
    public function get(string $uuid): array
    {
        $row = $this->requests->findByUuid($uuid) ?? throw new NotFoundException('Inspection request');

        return Representation::inspectionRequest($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function list(Pagination $page, array $filters): array
    {
        $result = $this->requests->paginate($page->perPage, $page->offset(), $filters);

        return $page->envelope(
            array_map([Representation::class, 'inspectionRequest'], $result['rows']),
            $result['total'],
        );
    }

    private function deadlineFor(DateTimeImmutable $requestedAt): DateTimeImmutable
    {
        return $requestedAt->modify("+{$this->noticeWindowHours} hours");
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
