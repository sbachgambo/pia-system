<?php

declare(strict_types=1);

namespace App\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Read-only aggregates for the dashboard charts. */
final class DashboardMetrics
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Inspections scheduled vs. finalised per calendar month, oldest first,
     * zero-filled so a quiet month still gets a (short) bar.
     *
     * @return list<array{label:string,scheduled:int,finalized:int}>
     */
    public function monthlyInspections(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $first = (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC')))
            ->modify('-' . ($months - 1) . ' months');

        $scheduled = $this->countByMonth('scheduled_at', $first);
        $finalized = $this->countByMonth('finalized_at', $first);

        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $first->modify("+{$i} months");
            $key = $m->format('Y-m');
            $out[] = [
                'label'     => $m->format('M'),
                'scheduled' => $scheduled[$key] ?? 0,
                'finalized' => $finalized[$key] ?? 0,
            ];
        }

        return $out;
    }

    /** @return array<string,int> status => count, all statuses present */
    public function requestStatusCounts(): array
    {
        $counts = ['pending' => 0, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM inspection_requests GROUP BY status')->fetchAll() as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }

        return $counts;
    }

    /** Inspectors currently flagged by the 3-strikes rule (most recent evaluated month). */
    public function complianceAlerts(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM compliance_tracking
              WHERE alert_triggered = 1 AND period_month = (SELECT MAX(period_month) FROM compliance_tracking)'
        )->fetchColumn();
    }

    /** @return array<string,int> "YYYY-MM" => count */
    private function countByMonth(string $column, DateTimeImmutable $since): array
    {
        // $column is one of two hard-coded names from monthlyInspections(), never input.
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT({$column}, '%Y-%m') AS ym, COUNT(*) AS n FROM inspections
              WHERE {$column} >= :since GROUP BY ym"
        );
        $stmt->execute(['since' => $since->format('Y-m-d H:i:s')]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['ym']] = (int) $r['n'];
        }

        return $out;
    }
}
