<?php

declare(strict_types=1);

namespace App\Inspections;

use PDO;

/**
 * Append-only writer for `sync_log` — one row per record processed by a device
 * push.
 */
final class SyncLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(
        string $recordUuid,
        string $recordType,
        int $syncedBy,
        ?string $deviceId,
        string $status,
        ?string $conflictResolution = null,
        ?string $detail = null,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO sync_log
                (record_uuid, record_type, synced_by, device_id, sync_status, conflict_resolution, detail,
                 synced_at, created_at, updated_at)
             VALUES (:ruid, :rtype, :by, :dev, :status, :conf, :detail, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'ruid'   => $recordUuid,
            'rtype'  => $recordType,
            'by'     => $syncedBy,
            'dev'    => $deviceId,
            'status' => $status,
            'conf'   => $conflictResolution,
            'detail' => $detail,
        ]);
    }
}
