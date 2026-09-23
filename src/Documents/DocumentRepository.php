<?php

declare(strict_types=1);

namespace App\Documents;

use PDO;

/**
 * Prepared-statement access to `documents` (brief §4). Reads join `inspections`
 * for `inspection_uuid` and `users` for `issued_by_uuid`; internal ids never
 * leave this layer.
 */
final class DocumentRepository
{
    private const SELECT =
        'd.id, d.uuid, i.uuid AS inspection_uuid, u.uuid AS issued_by_uuid, u.full_name AS issued_by_name,
         d.type, d.document_number, d.file_path, d.content_hash, d.hmac_signature, d.issued_at, d.status,
         d.created_at, d.updated_at';

    private const JOINS = 'FROM documents d JOIN inspections i ON i.id = d.inspection_id
                            LEFT JOIN users u ON u.id = d.issued_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record a newly issued certificate — and void the one it replaces.
     *
     * An inspection has at most ONE live certificate. Generating again (to
     * correct a CCI, or to issue an NNCI instead) supersedes the earlier one in
     * the same transaction; otherwise the CBN invoice and the income report
     * would bill the same shipment twice. Superseded certificates stay on file
     * (status `void`) and in the archive.
     *
     * @return array<string,mixed>
     */
    public function insert(
        string $uuid,
        int $inspectionId,
        ?int $issuedById,
        string $type,
        string $documentNumber,
        string $relativeFilePath,
        string $contentHash,
        string $hmacSignature,
        \DateTimeImmutable $issuedAt,
    ): array {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                "UPDATE documents SET status = 'void', updated_at = UTC_TIMESTAMP() WHERE inspection_id = :insp AND status = 'issued'"
            )->execute(['insp' => $inspectionId]);
            $this->insertRow($uuid, $inspectionId, $issuedById, $type, $documentNumber, $relativeFilePath, $contentHash, $hmacSignature, $issuedAt);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->findByUuid($uuid) ?? throw new \RuntimeException('document vanished after insert');
    }

    private function insertRow(
        string $uuid,
        int $inspectionId,
        ?int $issuedById,
        string $type,
        string $documentNumber,
        string $relativeFilePath,
        string $contentHash,
        string $hmacSignature,
        \DateTimeImmutable $issuedAt,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO documents
                (uuid, inspection_id, issued_by, type, document_number, file_path, content_hash,
                 hmac_signature, issued_at, status, created_at, updated_at)
             VALUES
                (:uuid, :insp, :by, :type, :num, :path, :hash, :sig, :issued, \'issued\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'uuid'   => $uuid,
            'insp'   => $inspectionId,
            'by'     => $issuedById,
            'type'   => $type,
            'num'    => $documentNumber,
            'path'   => $relativeFilePath,
            'hash'   => $contentHash,
            'sig'    => $hmacSignature,
            'issued' => $issuedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one('d.uuid = :v', ['v' => $uuid]);
    }

    /** @return list<array<string,mixed>> newest first */
    public function forInspection(int $inspectionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . ' WHERE d.inspection_id = :id ORDER BY d.id DESC'
        );
        $stmt->execute(['id' => $inspectionId]);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $params */
    private function one(string $condition, array $params): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SELECT . ' ' . self::JOINS . " WHERE {$condition} LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
