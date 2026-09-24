<?php

declare(strict_types=1);

namespace App\Inspections;

use App\Audit\AuditLog;
use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Office\NotFoundException;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * The office review workflow for a synced inspection (brief §5 + D1):
 *
 *   synced ──amend──▶ amended ──amend──▶ amended …
 *   synced ┐
 *   amended┴─finalize─▶ finalized   (locked; triggers doc generation in Phase 6)
 *   synced ┐
 *   amended┴─reject───▶ rejected    (back to the inspector; re-sync ⇒ synced)
 *
 * Every amend / finalize / reject writes a before/after snapshot to
 * `audit_log`. `finalized` and `rejected` are the only terminal-ish states
 * for the office: once finalized nothing here can touch it.
 */
final class ReviewService
{
    private const REVIEWABLE = ['synced', 'amended'];
    private const LOCATION_TYPES = ['factory', 'warehouse', 'port', 'other'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly InspectionRepository $inspections,
        private readonly FindingRepository $findings,
        private readonly AttachmentRepository $attachments,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array{status?:string} $filters
     * @return array<string,mixed>
     */
    public function queue(Pagination $page, array $filters): array
    {
        $result = $this->inspections->reviewQueue($page->perPage, $page->offset(), $filters);

        return $page->envelope(
            array_map(fn (array $r): array => $this->summary($r), $result['rows']),
            $result['total'],
        );
    }

    /** @return array<string,mixed> full detail incl. findings, attachments, audit trail */
    public function show(string $uuid): array
    {
        $row = $this->inspections->findByUuid($uuid) ?? throw new NotFoundException('Inspection');
        $id = (int) $row['id'];

        $payload = Representation::inspection(
            $row,
            $this->findings->forInspection($id),
            $this->attachments->forInspection($id),
        );
        $payload['audit_trail'] = $this->audit->forEntity('inspection', $id);

        return $payload;
    }

    /** @return array<string,mixed> */
    public function amend(string $uuid, Input $in, int $actorId, ?string $ip): array
    {
        $row = $this->guard($uuid);
        $id = (int) $row['id'];

        $locationType = $in->optionalEnum('location_type', self::LOCATION_TYPES);
        $locationDetail = $in->has('location_detail') ? $in->requiredString('location_detail', 255) : null;

        $newFindings = null;
        if ($in->has('findings')) {
            $newFindings = $this->parseFindings($in->raw('findings'));
        }

        if ($locationType === null && $locationDetail === null && $newFindings === null) {
            throw new ApiException(422, 'nothing_to_amend', 'Provide location_type, location_detail, and/or findings.');
        }

        $before = $this->snapshot($row);

        try {
            $this->pdo->beginTransaction();
            $updated = $this->inspections->applyAmend($id, $locationType, $locationDetail, self::REVIEWABLE);
            if ($newFindings !== null) {
                $this->findings->replaceForInspection($id, $newFindings);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $after = $this->snapshot($updated);
        $this->audit->record($actorId, 'inspection.amend', 'inspection', $id, $before, $after, $ip);

        return $this->show($uuid);
    }

    /** @return array<string,mixed> */
    public function finalize(string $uuid, int $actorId, ?string $ip): array
    {
        $row = $this->guard($uuid);
        $id = (int) $row['id'];

        $before = $this->snapshot($row);
        $updated = $this->inspections->applyFinalize($id, $actorId, new DateTimeImmutable('now', new DateTimeZone('UTC')), self::REVIEWABLE);

        $this->audit->record($actorId, 'inspection.finalize', 'inspection', $id, $before, $this->snapshot($updated), $ip);

        // Phase 6 hook: document generation (D2) fires off the 'finalized' state.

        return $this->show($uuid);
    }

    /** @return array<string,mixed> */
    public function reject(string $uuid, Input $in, int $actorId, ?string $ip): array
    {
        $row = $this->guard($uuid);
        $id = (int) $row['id'];
        $reason = $in->requiredString('reason', 1000);

        $before = $this->snapshot($row);
        $updated = $this->inspections->applyReject($id, self::REVIEWABLE);

        $this->audit->record(
            $actorId,
            'inspection.reject',
            'inspection',
            $id,
            $before,
            ['status' => 'rejected', 'reason' => $reason],
            $ip,
        );

        return $this->show($uuid);
    }

    // --- internals -----------------------------------------------------

    /** @return array<string,mixed> */
    private function guard(string $uuid): array
    {
        $row = $this->inspections->findByUuid($uuid) ?? throw new NotFoundException('Inspection');

        if (!in_array($row['status'], self::REVIEWABLE, true)) {
            throw new ApiException(
                422,
                'not_reviewable',
                "An inspection with status `{$row['status']}` cannot be amended, finalised, or rejected.",
                ['status' => $row['status']],
            );
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private function snapshot(array $row): array
    {
        $id = (int) $row['id'];

        return [
            'status'          => (string) $row['status'],
            'location_type'   => (string) $row['location_type'],
            'location_detail' => (string) $row['location_detail'],
            'findings'        => array_map([Representation::class, 'finding'], $this->findings->forInspection($id)),
        ];
    }

    /** @param array<string,mixed> $row */
    private function summary(array $row): array
    {
        return [
            'uuid'             => (string) $row['uuid'],
            'consignment_uuid' => (string) $row['consignment_uuid'],
            'inspector_uuid'   => (string) $row['inspector_uuid'],
            'inspector_name'   => (string) ($row['inspector_name'] ?? ''),
            'client_name'      => (string) ($row['client_name'] ?? ''),
            'product_category' => (string) ($row['product_category'] ?? ''),
            'nxp_number'       => isset($row['nxp_number']) ? (string) $row['nxp_number'] : null,
            'cci_number'       => isset($row['cci_number']) ? (string) $row['cci_number'] : null,
            'status'           => (string) $row['status'],
            'scheduled_at'     => $this->ts($row['scheduled_at']),
            'synced_at'        => $this->ts($row['synced_at'] ?? null),
            'finalized_at'     => $this->ts($row['finalized_at'] ?? null),
        ];
    }

    /**
     * @return list<array{checklist_item:string,expected_value:?string,observed_value:?string,result:string,notes:?string}>
     */
    private function parseFindings(mixed $findings): array
    {
        if (!is_array($findings)) {
            throw new ApiException(422, 'validation_failed', '`findings` must be an array.', ['field' => 'findings']);
        }

        $out = [];
        foreach ($findings as $i => $f) {
            if (!is_array($f)) {
                throw new ApiException(422, 'validation_failed', "findings[{$i}] must be an object.");
            }
            $item = trim((string) ($f['checklist_item'] ?? ''));
            $result = $f['result'] ?? null;
            if ($item === '' || mb_strlen($item) > 255) {
                throw new ApiException(422, 'validation_failed', "findings[{$i}].checklist_item is required (<=255).");
            }
            if (!in_array($result, ['pass', 'fail', 'flag'], true)) {
                throw new ApiException(422, 'validation_failed', "findings[{$i}].result must be pass|fail|flag.");
            }
            $out[] = [
                'checklist_item' => $item,
                'expected_value' => $this->nz($f['expected_value'] ?? null, 255),
                'observed_value' => $this->nz($f['observed_value'] ?? null, 255),
                'result'         => $result,
                'notes'          => $this->nz($f['notes'] ?? null, 65535),
            ];
        }

        return $out;
    }

    private function nz(mixed $v, int $max): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    private function ts(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
