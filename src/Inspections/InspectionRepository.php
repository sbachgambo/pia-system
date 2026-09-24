<?php

declare(strict_types=1);

namespace App\Inspections;

use App\Support\ApiException;
use DateTimeImmutable;
use PDO;

/**
 * Prepared-statement access to `inspections`. Serves both the office
 * scheduling step (which creates the row) and the field-sync path (which
 * updates it). Internal `id` never leaves the service layer.
 */
final class InspectionRepository
{
    private const SELECT =
        'i.id, i.uuid, ir.uuid AS inspection_request_uuid, u.uuid AS inspector_uuid,
         co.uuid AS consignment_uuid, i.scheduled_at, i.location_type, i.location_detail, i.status,
         i.started_at, i.synced_at, i.finalized_at, i.device_id, i.created_at, i.updated_at,
         cl.name AS client_name, co.product_category, u.full_name AS inspector_name,
         co.form_nxp_number AS nxp_number, co.declared_value, co.currency,
         (SELECT d.document_number FROM documents d
           WHERE d.inspection_id = i.id AND d.status = \'issued\' ORDER BY d.id DESC LIMIT 1) AS cci_number';

    private const JOINS =
        'FROM inspections i
         JOIN inspection_requests ir ON ir.id = i.inspection_request_id
         JOIN users u ON u.id = i.inspector_id
         JOIN consignments co ON co.id = ir.consignment_id
         JOIN clients cl ON cl.id = co.client_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function createScheduled(
        string $uuid,
        int $inspectionRequestId,
        int $inspectorId,
        DateTimeImmutable $scheduledAt,
        string $locationType,
        string $locationDetail,
    ): array {
        $this->pdo->prepare(
            'INSERT INTO inspections
                (uuid, inspection_request_id, inspector_id, scheduled_at, location_type, location_detail,
                 status, created_at, updated_at)
             VALUES (:uuid, :req, :insp, :sched, :ltype, :ldetail, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'uuid'    => $uuid,
            'req'     => $inspectionRequestId,
            'insp'    => $inspectorId,
            'sched'   => $scheduledAt->format('Y-m-d H:i:s'),
            'ltype'   => $locationType,
            'ldetail' => $locationDetail,
            'status'  => 'scheduled',
        ]);

        return $this->findByUuid($uuid) ?? throw new \RuntimeException('inspection vanished after insert');
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one('i.uuid = :v', ['v' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuidForInspector(string $uuid, int $inspectorId): ?array
    {
        return $this->one('i.uuid = :v AND i.inspector_id = :insp', ['v' => $uuid, 'insp' => $inspectorId]);
    }

    /**
     * The inspector's working set for offline caching: everything not yet
     * finalised or rejected.
     *
     * @return list<array<string,mixed>>
     */
    public function listAssignedTo(int $inspectorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . "
              WHERE i.inspector_id = :insp
                AND i.status IN ('scheduled', 'in_progress', 'synced', 'amended', 'rejected')
           ORDER BY i.scheduled_at ASC"
        );
        $stmt->execute(['insp' => $inspectorId]);

        return $stmt->fetchAll();
    }

    /**
     * Apply a field sync to the inspection row itself (status / timestamps /
     * device). Findings and attachments are handled by their own repos.
     *
     * @return array<string,mixed>
     */
    public function applySync(
        int $id,
        string $status,
        ?DateTimeImmutable $startedAt,
        DateTimeImmutable $syncedAt,
        ?string $deviceId,
    ): array {
        $this->pdo->prepare(
            'UPDATE inspections
                SET status = :status,
                    started_at = COALESCE(:started, started_at),
                    synced_at = :synced,
                    device_id = COALESCE(:device, device_id),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        )->execute([
            'status'  => $status,
            'started' => $startedAt?->format('Y-m-d H:i:s'),
            'synced'  => $syncedAt->format('Y-m-d H:i:s'),
            'device'  => $deviceId,
            'id'      => $id,
        ]);

        return $this->one('i.id = :v', ['v' => $id]) ?? throw new \RuntimeException('inspection not found after sync');
    }

    public function findById(int $id): ?array
    {
        return $this->one('i.id = :v', ['v' => $id]);
    }

    /**
     * Office review queue: inspections awaiting or under review.
     *
     * @param array{status?:string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function reviewQueue(int $limit, int $offset, array $filters = []): array
    {
        $where = "i.status IN ('synced', 'amended', 'rejected', 'finalized')";
        $params = [];

        if (!empty($filters['status'])) {
            $where = 'i.status = :status';
            $params['status'] = $filters['status'];
        }

        $total = (int) $this->run("SELECT COUNT(*) " . self::JOINS . " WHERE {$where}", $params)->fetchColumn();

        $rows = $this->run(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . " WHERE {$where}
             ORDER BY i.synced_at DESC, i.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params,
        )->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Correction by the office (status -> amended). Only the two location
     * fields are amendable at the row level; findings are replaced by the
     * FindingRepository inside the same transaction.
     *
     * @return array<string,mixed>
     */
    public function applyAmend(int $id, ?string $locationType, ?string $locationDetail, array $expected): array
    {
        [$in, $params] = $this->statusCondition($expected);

        $stmt = $this->pdo->prepare(
            "UPDATE inspections
                SET status = 'amended',
                    location_type = COALESCE(:ltype, location_type),
                    location_detail = COALESCE(:ldetail, location_detail),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND status IN ({$in})"
        );
        $stmt->execute($params + [
            'ltype'   => $locationType,
            'ldetail' => $locationDetail,
            'id'      => $id,
        ]);
        $this->assertChanged($stmt->rowCount(), $id, 'amended');

        return $this->one('i.id = :v', ['v' => $id]) ?? throw new \RuntimeException('inspection not found after amend');
    }

    /**
     * @param list<string> $expected statuses this change may be applied to
     * @return array<string,mixed>
     */
    public function applyFinalize(int $id, int $finalizedByUserId, DateTimeImmutable $when, array $expected): array
    {
        [$in, $params] = $this->statusCondition($expected);

        $stmt = $this->pdo->prepare(
            "UPDATE inspections
                SET status = 'finalized', finalized_at = :when, finalized_by = :by, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND status IN ({$in})"
        );
        $stmt->execute($params + ['when' => $when->format('Y-m-d H:i:s'), 'by' => $finalizedByUserId, 'id' => $id]);
        $this->assertChanged($stmt->rowCount(), $id, 'finalized');

        return $this->one('i.id = :v', ['v' => $id]) ?? throw new \RuntimeException('inspection not found after finalize');
    }

    /**
     * @param list<string> $expected statuses this change may be applied to
     * @return array<string,mixed>
     */
    public function applyReject(int $id, array $expected): array
    {
        [$in, $params] = $this->statusCondition($expected);

        $stmt = $this->pdo->prepare(
            "UPDATE inspections SET status = 'rejected', updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND status IN ({$in})"
        );
        $stmt->execute($params + ['id' => $id]);
        $this->assertChanged($stmt->rowCount(), $id, 'rejected');

        return $this->one('i.id = :v', ['v' => $id]) ?? throw new \RuntimeException('inspection not found after reject');
    }

    /**
     * Builds `IN (:st0, :st1, …)` with one placeholder per value — emulated
     * prepares are off, so a name may not be reused within a statement.
     *
     * @param list<string> $statuses
     * @return array{0:string, 1:array<string,string>}
     */
    private function statusCondition(array $statuses): array
    {
        $names = [];
        $params = [];
        foreach (array_values($statuses) as $i => $status) {
            $names[] = ":st{$i}";
            $params["st{$i}"] = $status;
        }

        return [implode(', ', $names), $params];
    }

    /**
     * The caller checked the status before calling, but another request may
     * have moved the inspection on in between. Matching zero rows means that
     * happened, so the change is refused rather than silently lost — without
     * this, two submissions could both pass the guard and the second would
     * overwrite the first.
     */
    private function assertChanged(int $rowCount, int $id, string $target): void
    {
        if ($rowCount === 0) {
            $current = $this->one('i.id = :v', ['v' => $id]);

            throw new ApiException(
                409,
                'concurrent_update',
                $current === null
                    ? 'That inspection no longer exists.'
                    : "This inspection is already `{$current['status']}` — someone else changed it while this page was open. Reload and try again.",
                ['status' => $current['status'] ?? null, 'attempted' => $target],
            );
        }
    }

    /**
     * Every inspection raised under one request, newest first (a rejected one
     * can be followed by a fresh assignment).
     *
     * @return list<array<string,mixed>>
     */
    public function forRequest(string $inspectionRequestUuid): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SELECT . ' ' . self::JOINS . ' WHERE ir.uuid = :u ORDER BY i.scheduled_at DESC, i.id DESC');
        $stmt->execute(['u' => $inspectionRequestUuid]);

        return $stmt->fetchAll();
    }

    /**
     * Active inspectors, for the "assign inspector" picker.
     *
     * @return list<array{uuid:string,full_name:string,email:string,zone:?string}>
     */
    public function activeInspectors(): array
    {
        return $this->pdo->query(
            "SELECT uuid, full_name, email, zone FROM users WHERE role = 'inspector' AND status = 'active' ORDER BY full_name"
        )->fetchAll();
    }

    public function requestIsScheduled(string $inspectionRequestUuid): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM inspection_requests WHERE uuid = :u AND status IN ('pending','scheduled') LIMIT 1"
        );
        $stmt->execute(['u' => $inspectionRequestUuid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function one(string $condition, array $params): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::SELECT . ' ' . self::JOINS . " WHERE {$condition} LIMIT 1");
        $stmt->execute($params);
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
}
