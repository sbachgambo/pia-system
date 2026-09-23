<?php

declare(strict_types=1);

namespace App\Inspections;

use PDO;

/**
 * `inspection_findings` access. The sync unit is the whole inspection, so the
 * finding set is replaced wholesale for an inspection on each sync
 * (delete-then-insert inside the sync transaction).
 */
final class FindingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<array{checklist_item:string,expected_value:?string,observed_value:?string,result:string,notes:?string}> $findings */
    public function replaceForInspection(int $inspectionId, array $findings): void
    {
        $this->pdo->prepare('DELETE FROM inspection_findings WHERE inspection_id = :id')
            ->execute(['id' => $inspectionId]);

        if ($findings === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO inspection_findings
                (inspection_id, checklist_item, expected_value, observed_value, result, notes, created_at, updated_at)
             VALUES (:id, :item, :expected, :observed, :result, :notes, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );

        foreach ($findings as $f) {
            $insert->execute([
                'id'       => $inspectionId,
                'item'     => $f['checklist_item'],
                'expected' => $f['expected_value'],
                'observed' => $f['observed_value'],
                'result'   => $f['result'],
                'notes'    => $f['notes'],
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function forInspection(int $inspectionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT checklist_item, expected_value, observed_value, result, notes
               FROM inspection_findings WHERE inspection_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['id' => $inspectionId]);

        return $stmt->fetchAll();
    }

    public function countByResult(int $inspectionId, string $result): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM inspection_findings WHERE inspection_id = :id AND result = :r'
        );
        $stmt->execute(['id' => $inspectionId, 'r' => $result]);

        return (int) $stmt->fetchColumn();
    }
}
