<?php

declare(strict_types=1);

namespace App\Inspections;

use DateTimeImmutable;
use PDO;

/**
 * `inspection_attachments` access. A row is created (as a stub, `file_path`
 * null) when the inspection JSON syncs; the binary arrives later via the
 * upload endpoint, which fills in `file_path` / `uploaded_at` / mime / size.
 * Idempotent on `client_uuid`.
 */
final class AttachmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Upsert a stub for a declared attachment (called during inspection sync).
     *
     * @param array{client_uuid:string,file_type:string,captured_at:string,checksum_sha256:string,original_name:?string} $a
     */
    public function upsertStub(int $inspectionId, array $a): void
    {
        $this->pdo->prepare(
            'INSERT INTO inspection_attachments
                (inspection_id, client_uuid, file_type, original_name, captured_at, checksum_sha256,
                 created_at, updated_at)
             VALUES (:iid, :cuid, :ftype, :oname, :captured, :sha, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                file_type = VALUES(file_type),
                original_name = VALUES(original_name),
                captured_at = VALUES(captured_at),
                checksum_sha256 = VALUES(checksum_sha256),
                updated_at = UTC_TIMESTAMP()'
        )->execute([
            'iid'     => $inspectionId,
            'cuid'    => $a['client_uuid'],
            'ftype'   => $a['file_type'],
            'oname'   => $a['original_name'],
            'captured' => $a['captured_at'],
            'sha'     => $a['checksum_sha256'],
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByClientUuid(string $clientUuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.client_uuid, a.inspection_id, a.file_path, a.file_type, a.mime_type, a.byte_size,
                    a.captured_at, a.uploaded_at, a.checksum_sha256, i.inspector_id, i.status AS inspection_status
               FROM inspection_attachments a
               JOIN inspections i ON i.id = a.inspection_id
              WHERE a.client_uuid = :c LIMIT 1'
        );
        $stmt->execute(['c' => $clientUuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.client_uuid, a.inspection_id, a.file_path, a.file_type, a.mime_type, a.byte_size,
                    a.original_name, a.uploaded_at, i.inspector_id
               FROM inspection_attachments a
               JOIN inspections i ON i.id = a.inspection_id
              WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function markUploaded(int $id, string $filePath, string $mimeType, int $byteSize, string $sha, DateTimeImmutable $when): void
    {
        $this->pdo->prepare(
            'UPDATE inspection_attachments
                SET file_path = :fp, mime_type = :mt, byte_size = :bs, checksum_sha256 = :sha,
                    uploaded_at = :when, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        )->execute([
            'fp'   => $filePath,
            'mt'   => $mimeType,
            'bs'   => $byteSize,
            'sha'  => $sha,
            'when' => $when->format('Y-m-d H:i:s'),
            'id'   => $id,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function forInspection(int $inspectionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT client_uuid, file_type, original_name, mime_type, byte_size, captured_at, uploaded_at, checksum_sha256
               FROM inspection_attachments WHERE inspection_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['id' => $inspectionId]);

        return $stmt->fetchAll();
    }
}
