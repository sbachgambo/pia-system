<?php

declare(strict_types=1);

namespace App\Compliance;

use DateTimeImmutable;
use PDO;

/**
 * Prepared-statement access to `compliance_tracking` (brief §4). Reads join
 * `users` for `inspector_uuid`/name; internal ids never leave this layer.
 */
final class ComplianceRepository
{
    private const SELECT =
        'ct.id, u.uuid AS inspector_uuid, u.full_name AS inspector_name, u.zone AS inspector_zone,
         ct.period_month, ct.missed_windows_count, ct.consecutive_miss_count, ct.alert_triggered,
         ct.last_evaluated_at, ct.created_at, ct.updated_at';

    private const JOINS = 'FROM compliance_tracking ct JOIN users u ON u.id = ct.inspector_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function upsert(
        int $inspectorId,
        DateTimeImmutable $periodMonth,
        int $missedWindowsCount,
        int $consecutiveMissCount,
        bool $alertTriggered,
        DateTimeImmutable $evaluatedAt,
    ): array {
        $this->pdo->prepare(
            'INSERT INTO compliance_tracking
                (inspector_id, period_month, missed_windows_count, consecutive_miss_count, alert_triggered,
                 last_evaluated_at, created_at, updated_at)
             VALUES (:insp, :month, :missed, :streak, :alert, :evaluated, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                missed_windows_count = VALUES(missed_windows_count),
                consecutive_miss_count = VALUES(consecutive_miss_count),
                alert_triggered = VALUES(alert_triggered),
                last_evaluated_at = VALUES(last_evaluated_at),
                updated_at = UTC_TIMESTAMP()'
        )->execute([
            'insp'      => $inspectorId,
            'month'     => $periodMonth->format('Y-m-d'),
            'missed'    => $missedWindowsCount,
            'streak'    => $consecutiveMissCount,
            'alert'     => $alertTriggered ? 1 : 0,
            'evaluated' => $evaluatedAt->format('Y-m-d H:i:s'),
        ]);

        return $this->one('ct.inspector_id = :i AND ct.period_month = :m', ['i' => $inspectorId, 'm' => $periodMonth->format('Y-m-d')])
            ?? throw new \RuntimeException('compliance_tracking row vanished after upsert');
    }

    /** @return array<string,mixed>|null */
    public function forInspectorMonth(int $inspectorId, DateTimeImmutable $periodMonth): ?array
    {
        return $this->one('ct.inspector_id = :i AND ct.period_month = :m', [
            'i' => $inspectorId, 'm' => $periodMonth->format('Y-m-d'),
        ]);
    }

    /**
     * Inspectors with at least one inspection scheduled in [$start, $end) —
     * the set ComplianceEvaluator::evaluateMonth() evaluates. An inspector
     * with nothing scheduled that month gets no row at all, rather than a
     * misleading "0 missed" entry.
     *
     * @return list<int>
     */
    public function inspectorIdsScheduledBetween(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT inspector_id FROM inspections WHERE scheduled_at >= :start AND scheduled_at < :end'
        );
        $stmt->execute(['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array{alert_triggered?:bool, inspector_uuid?:string, period_month?:string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function paginate(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $total = (int) $this->run("SELECT COUNT(*) " . self::JOINS . " {$where}", $params)->fetchColumn();
        $rows = $this->run(
            'SELECT ' . self::SELECT . ' ' . self::JOINS . " {$where}
             ORDER BY ct.period_month DESC, ct.alert_triggered DESC, u.full_name ASC LIMIT {$limit} OFFSET {$offset}",
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

        if (array_key_exists('alert_triggered', $filters) && $filters['alert_triggered'] !== null) {
            $clauses[] = 'ct.alert_triggered = :alert';
            $params['alert'] = $filters['alert_triggered'] ? 1 : 0;
        }
        if (!empty($filters['inspector_uuid'])) {
            $clauses[] = 'u.uuid = :iu';
            $params['iu'] = $filters['inspector_uuid'];
        }
        if (!empty($filters['period_month'])) {
            $clauses[] = 'ct.period_month = :pm';
            $params['pm'] = $filters['period_month'];
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,mixed> $params */
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
