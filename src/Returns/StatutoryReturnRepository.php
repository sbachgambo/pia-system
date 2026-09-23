<?php

declare(strict_types=1);

namespace App\Returns;

use DateTimeImmutable;
use PDO;

/**
 * Prepared-statement access to `statutory_returns` (brief §4). Reads join
 * `users` for `generated_by_uuid`/`submitted_by_uuid`; internal ids never
 * leave this layer.
 */
final class StatutoryReturnRepository
{
    private const SELECT =
        'sr.id, sr.uuid, sr.agency, sr.period_start, sr.period_end, sr.file_path, sr.format, sr.row_count,
         sr.generated_at, sr.submitted_at, sr.status,
         gu.uuid AS generated_by_uuid, gu.full_name AS generated_by_name,
         su.uuid AS submitted_by_uuid, su.full_name AS submitted_by_name';

    private const JOINS = 'FROM statutory_returns sr
                            LEFT JOIN users gu ON gu.id = sr.generated_by
                            LEFT JOIN users su ON su.id = sr.submitted_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function insert(
        string $uuid,
        string $agency,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        string $relativeFilePath,
        string $format,
        int $rowCount,
        ?int $generatedBy,
        DateTimeImmutable $generatedAt,
    ): array {
        $this->pdo->prepare(
            "INSERT INTO statutory_returns
                (uuid, agency, period_start, period_end, file_path, format, row_count, generated_at,
                 generated_by, status, created_at, updated_at)
             VALUES (:uuid, :agency, :start, :end, :path, :format, :rows, :generated, :by, 'generated',
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            'uuid'      => $uuid,
            'agency'    => $agency,
            'start'     => $periodStart->format('Y-m-d'),
            'end'       => $periodEnd->format('Y-m-d'),
            'path'      => $relativeFilePath,
            'format'    => $format,
            'rows'      => $rowCount,
            'generated' => $generatedAt->format('Y-m-d H:i:s'),
            'by'        => $generatedBy,
        ]);

        return $this->findByUuid($uuid) ?? throw new \RuntimeException('statutory_return vanished after insert');
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one('sr.uuid = :v', ['v' => $uuid]);
    }

    public function markSubmitted(int $id, int $submittedBy, DateTimeImmutable $when): array
    {
        $this->pdo->prepare(
            "UPDATE statutory_returns SET status = 'submitted', submitted_at = :when, submitted_by = :by,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id"
        )->execute(['when' => $when->format('Y-m-d H:i:s'), 'by' => $submittedBy, 'id' => $id]);

        return $this->one('sr.id = :v', ['v' => $id]) ?? throw new \RuntimeException('statutory_return not found after submit');
    }

    /**
     * @param array{agency?:string, status?:string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->run("SELECT COUNT(*) " . self::JOINS . " {$where}", $params)->fetchColumn();
        $rows = $this->run(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . " {$where}
             ORDER BY sr.generated_at DESC, sr.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['agency'])) {
            $clauses[] = 'sr.agency = :agency';
            $params['agency'] = $filters['agency'];
        }
        if (!empty($filters['status'])) {
            $clauses[] = 'sr.status = :status';
            $params['status'] = $filters['status'];
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,mixed> $id */
    private function one(string $condition, array $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SELECT . ' ' . self::JOINS . " WHERE {$condition} LIMIT 1");
        $stmt->execute($id);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public function findIdByUuid(string $uuid): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM statutory_returns WHERE uuid = :u LIMIT 1');
        $stmt->execute(['u' => $uuid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
