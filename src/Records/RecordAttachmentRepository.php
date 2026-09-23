<?php

declare(strict_types=1);

namespace App\Records;

use PDO;

/** Prepared-statement access to `record_attachments`. */
final class RecordAttachmentRepository
{
    private const SELECT = 'a.id, a.uuid, a.entity_type, a.entity_id, a.original_name, a.file_path, a.mime_type,
                            a.byte_size, a.checksum_sha256, a.uploaded_by, u.full_name AS uploaded_by_name, a.created_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(string $uuid, array $data): array
    {
        $this->pdo->prepare(
            'INSERT INTO record_attachments
                (uuid, entity_type, entity_id, original_name, file_path, mime_type, byte_size, checksum_sha256, uploaded_by, created_at, updated_at)
             VALUES (:uuid, :type, :eid, :name, :path, :mime, :size, :sha, :by, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'uuid' => $uuid,
            'type' => $data['entity_type'],
            'eid'  => $data['entity_id'],
            'name' => $data['original_name'],
            'path' => $data['file_path'],
            'mime' => $data['mime_type'],
            'size' => $data['byte_size'],
            'sha'  => $data['checksum_sha256'],
            'by'   => $data['uploaded_by'],
        ]);

        return $this->findByUuid($uuid) ?? throw new \RuntimeException('attachment vanished after insert');
    }

    /** @return list<array<string,mixed>> newest first */
    public function forEntity(string $type, int $entityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM record_attachments a JOIN users u ON u.id = a.uploaded_by
              WHERE a.entity_type = :t AND a.entity_id = :e ORDER BY a.created_at DESC, a.id DESC'
        );
        $stmt->execute(['t' => $type, 'e' => $entityId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' FROM record_attachments a JOIN users u ON u.id = a.uploaded_by WHERE a.uuid = :u LIMIT 1'
        );
        $stmt->execute(['u' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM record_attachments WHERE id = :id')->execute(['id' => $id]);
    }
}
