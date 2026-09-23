<?php

declare(strict_types=1);

namespace App\Office;

use DateTimeImmutable;
use PDO;

/**
 * Prepared-statement access to `inspection_requests`. Reads join `consignments`
 * and `users` so callers get `consignment_uuid` / `requested_by_uuid`.
 */
final class InspectionRequestRepository
{
    private const SELECT =
        'ir.id, ir.uuid, co.uuid AS consignment_uuid, u.uuid AS requested_by_uuid, u.full_name AS requested_by_name,
         ir.requested_at, ir.notice_deadline, ir.status, ir.created_at, ir.updated_at,
         cl.name AS client_name, co.product_category, co.form_nxp_number AS nxp_number';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function create(
        string $uuid,
        int $consignmentId,
        int $requestedById,
        DateTimeImmutable $requestedAt,
        DateTimeImmutable $noticeDeadline,
    ): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inspection_requests
                (uuid, consignment_id, requested_by, requested_at, notice_deadline, status, created_at, updated_at)
             VALUES
                (:uuid, :consignment_id, :requested_by, :requested_at, :notice_deadline, :status,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uuid'            => $uuid,
            'consignment_id'  => $consignmentId,
            'requested_by'    => $requestedById,
            'requested_at'    => $requestedAt->format('Y-m-d H:i:s'),
            'notice_deadline' => $noticeDeadline->format('Y-m-d H:i:s'),
            'status'          => 'pending',
        ]);

        return $this->findByUuid($uuid) ?? throw new \RuntimeException('inspection request vanished after insert');
    }

    /** @return array<string,mixed> */
    public function updateSchedule(int $id, DateTimeImmutable $requestedAt, DateTimeImmutable $noticeDeadline): array
    {
        $this->pdo->prepare(
            'UPDATE inspection_requests
                SET requested_at = :requested_at, notice_deadline = :notice_deadline, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        )->execute([
            'id'              => $id,
            'requested_at'    => $requestedAt->format('Y-m-d H:i:s'),
            'notice_deadline' => $noticeDeadline->format('Y-m-d H:i:s'),
        ]);

        return $this->findById($id) ?? throw new \RuntimeException('inspection request not found after update');
    }

    /** @return array<string,mixed> */
    public function updateStatus(int $id, string $status): array
    {
        $this->pdo->prepare(
            'UPDATE inspection_requests SET status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute(['id' => $id, 'status' => $status]);

        return $this->findById($id) ?? throw new \RuntimeException('inspection request not found after status change');
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one('ir.uuid = :v', ['v' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->one('ir.id = :v', ['v' => $id]);
    }

    /**
     * @param array{status?:string, consignment_uuid?:string, overdue?:bool} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $joins = 'FROM inspection_requests ir
                  JOIN consignments co ON co.id = ir.consignment_id
                  JOIN clients cl ON cl.id = co.client_id
                  JOIN users u ON u.id = ir.requested_by';

        $total = (int) $this->run("SELECT COUNT(*) {$joins} {$where}", $params)->fetchColumn();

        $rows = $this->run(
            'SELECT ' . self::SELECT . " {$joins} {$where}
             ORDER BY ir.requested_at DESC, ir.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function one(string $condition, array $params): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . '
               FROM inspection_requests ir
               JOIN consignments co ON co.id = ir.consignment_id
               JOIN clients cl ON cl.id = co.client_id
               JOIN users u ON u.id = ir.requested_by
              WHERE ' . $condition . ' LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'ir.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['consignment_uuid'])) {
            $clauses[] = 'co.uuid = :consignment_uuid';
            $params['consignment_uuid'] = $filters['consignment_uuid'];
        }
        if (!empty($filters['overdue'])) {
            // still open and past the deadline
            $clauses[] = "ir.status IN ('pending','scheduled') AND ir.notice_deadline < UTC_TIMESTAMP()";
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }
}
