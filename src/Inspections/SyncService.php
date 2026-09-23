<?php

declare(strict_types=1);

namespace App\Inspections;

use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * POST /api/inspections/sync — batch upload of field-captured inspections.
 *
 * Idempotent on the inspection `uuid` (§3): the server never creates an
 * inspection here (the office did that when scheduling — A18); it accepts an
 * update for a uuid it already has, or rejects the record. Re-sending the
 * same batch after a dropped connection converges to the same state and
 * cannot duplicate.
 *
 * Per record the outcome is reported back AND written to `sync_log`.
 * `finalized` / `rejected` inspections are locked (D1) — a late field push is
 * rejected as a conflict, not applied.
 */
final class SyncService
{
    private const MAX_BATCH = 50;
    // `rejected` is syncable: the office bounced it back for correction and a
    // re-sync moves it to `synced` for another review (A21).
    private const SYNCABLE  = ['scheduled', 'in_progress', 'synced', 'amended', 'rejected'];
    private const RESULTS   = ['pass', 'fail', 'flag'];
    private const FILE_TYPES = ['photo', 'document', 'other'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly InspectionRepository $inspections,
        private readonly FindingRepository $findings,
        private readonly AttachmentRepository $attachments,
        private readonly SyncLogRepository $syncLog,
    ) {
    }

    /**
     * @param array<string,mixed> $body      decoded request body
     * @param int    $inspectorId  the authenticated inspector's id
     * @return array<string,mixed> { synced_at, results: [...] }
     */
    public function sync(array $body, int $inspectorId, ?string $deviceId): array
    {
        $batch = $body['inspections'] ?? null;

        if (!is_array($batch)) {
            throw new ApiException(422, 'invalid_body', '`inspections` must be an array.');
        }
        if (count($batch) > self::MAX_BATCH) {
            throw new ApiException(422, 'batch_too_large', 'At most ' . self::MAX_BATCH . ' inspections per sync.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $results = [];

        foreach ($batch as $raw) {
            $results[] = is_array($raw)
                ? $this->syncOne($raw, $inspectorId, $deviceId, $now)
                : ['uuid' => null, 'status' => 'rejected', 'reason' => 'malformed_record'];
        }

        return ['synced_at' => $now->format(DATE_ATOM), 'results' => $results];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function syncOne(array $raw, int $inspectorId, ?string $deviceId, DateTimeImmutable $now): array
    {
        $uuid = is_string($raw['uuid'] ?? null) ? $raw['uuid'] : null;
        if ($uuid === null || !preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            return ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'invalid_uuid'];
        }

        $row = $this->inspections->findByUuidForInspector($uuid, $inspectorId);
        if ($row === null) {
            $this->syncLog->record($uuid, 'inspection', $inspectorId, $deviceId, 'conflict', 'not_assigned');
            return ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'not_assigned'];
        }

        if (!in_array($row['status'], self::SYNCABLE, true)) {
            $this->syncLog->record($uuid, 'inspection', $inspectorId, $deviceId, 'conflict', 'locked:' . $row['status']);
            return ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'locked', 'inspection_status' => $row['status']];
        }

        try {
            $newStatus = $this->resolveStatus($raw['status'] ?? null);
            $findings  = $this->parseFindings($raw['findings'] ?? []);
            $atts      = $this->parseAttachments($raw['attachments'] ?? []);
            $startedAt = $this->parseTime($raw['started_at'] ?? null);
        } catch (ApiException $e) {
            $this->syncLog->record($uuid, 'inspection', $inspectorId, $deviceId, 'error', null, $e->getMessage());
            return ['uuid' => $uuid, 'status' => 'rejected', 'reason' => 'validation', 'detail' => $e->getMessage()];
        }

        $id = (int) $row['id'];

        try {
            $this->pdo->beginTransaction();

            $updated = $this->inspections->applySync($id, $newStatus, $startedAt, $now, $deviceId);
            $this->findings->replaceForInspection($id, $findings);
            foreach ($atts as $a) {
                $this->attachments->upsertStub($id, $a);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->syncLog->record($uuid, 'inspection', $inspectorId, $deviceId, 'error', null, 'write_failed');
            throw $e; // a genuine DB fault -> 500, the whole request is retryable
        }

        $this->syncLog->record($uuid, 'inspection', $inspectorId, $deviceId, 'success');

        $pending = array_values(array_filter(
            array_map(static fn (array $a): string => $a['client_uuid'], $atts),
            fn (string $cu): bool => $this->needsBlob($cu),
        ));

        return [
            'uuid'                => $uuid,
            'status'              => 'accepted',
            'inspection_status'   => $updated['status'],
            'attachments_pending' => $pending,
        ];
    }

    private function resolveStatus(mixed $status): string
    {
        if ($status === null) {
            return 'synced';
        }
        if (!in_array($status, ['in_progress', 'synced'], true)) {
            throw new ApiException(422, 'validation_failed', 'status must be in_progress or synced.');
        }

        return $status;
    }

    /**
     * @return list<array{checklist_item:string,expected_value:?string,observed_value:?string,result:string,notes:?string}>
     */
    private function parseFindings(mixed $findings): array
    {
        if (!is_array($findings)) {
            throw new ApiException(422, 'validation_failed', '`findings` must be an array.');
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
            if (!in_array($result, self::RESULTS, true)) {
                throw new ApiException(422, 'validation_failed', "findings[{$i}].result must be pass|fail|flag.");
            }
            $out[] = [
                'checklist_item' => $item,
                'expected_value' => $this->nullableStr($f['expected_value'] ?? null, 255),
                'observed_value' => $this->nullableStr($f['observed_value'] ?? null, 255),
                'result'         => $result,
                'notes'          => $this->nullableStr($f['notes'] ?? null, 65535),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{client_uuid:string,file_type:string,captured_at:string,checksum_sha256:string,original_name:?string}>
     */
    private function parseAttachments(mixed $attachments): array
    {
        if (!is_array($attachments)) {
            throw new ApiException(422, 'validation_failed', '`attachments` must be an array.');
        }

        $out = [];
        foreach ($attachments as $i => $a) {
            if (!is_array($a)) {
                throw new ApiException(422, 'validation_failed', "attachments[{$i}] must be an object.");
            }
            $cu = (string) ($a['client_uuid'] ?? '');
            if (!preg_match('/^[0-9a-fA-F-]{36}$/', $cu)) {
                throw new ApiException(422, 'validation_failed', "attachments[{$i}].client_uuid is invalid.");
            }
            if (!in_array($a['file_type'] ?? null, self::FILE_TYPES, true)) {
                throw new ApiException(422, 'validation_failed', "attachments[{$i}].file_type must be photo|document|other.");
            }
            $sha = strtolower((string) ($a['checksum_sha256'] ?? ''));
            if (!preg_match('/^[0-9a-f]{64}$/', $sha)) {
                throw new ApiException(422, 'validation_failed', "attachments[{$i}].checksum_sha256 must be a SHA-256 hex digest.");
            }
            $out[] = [
                'client_uuid'     => strtolower($cu),
                'file_type'       => (string) $a['file_type'],
                'captured_at'     => ($this->parseTime($a['captured_at'] ?? null) ?? new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'checksum_sha256' => $sha,
                'original_name'   => $this->nullableStr($a['original_name'] ?? null, 255),
            ];
        }

        return $out;
    }

    private function parseTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new ApiException(422, 'validation_failed', 'Invalid timestamp in payload.');
        }
    }

    private function nullableStr(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = (string) $value;

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    private function needsBlob(string $clientUuid): bool
    {
        $row = $this->attachments->findByClientUuid($clientUuid);

        return $row === null || $row['file_path'] === null;
    }
}
