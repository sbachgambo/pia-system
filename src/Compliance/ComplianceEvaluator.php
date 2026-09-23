<?php

declare(strict_types=1);

namespace App\Compliance;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Evaluates the "3-strikes" compliance tracking (brief §4, Phase 7). Run
 * nightly (§8) via `bin/evaluate-compliance.php`, and on demand from the
 * console/API.
 *
 * "Missed window" (Q9, resolved as **A34**, reversible/configurable): an
 * inspection whose `scheduled_at + COMPLIANCE_GRACE_HOURS` has passed while
 * it is still `scheduled` or `in_progress` — the inspector never got it to
 * `synced` (or further). An inspection that was completed late still counts
 * as NOT missed; one that was later `rejected` by the office for quality
 * reasons also doesn't count here (attendance, not review outcome, is what
 * this table tracks).
 *
 * `consecutive_miss_count` carries a streak across months: it increments
 * only when the streak is unbroken from the immediately preceding month and
 * that preceding month has already been evaluated; otherwise a month with
 * misses starts a fresh streak at 1. `alert_triggered` flips on at
 * `COMPLIANCE_STRIKE_THRESHOLD` (default 3).
 */
final class ComplianceEvaluator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ComplianceRepository $tracking,
        private readonly int $graceHours = 24,
        private readonly int $strikeThreshold = 3,
    ) {
    }

    /**
     * Evaluate every inspector with at least one inspection scheduled in the
     * given month. `$monthStart` can be any date in the target month.
     *
     * @return list<array<string,mixed>>
     */
    public function evaluateMonth(DateTimeImmutable $monthStart): array
    {
        $start = $this->firstOfMonth($monthStart);
        $end = $start->modify('+1 month');

        $results = [];
        foreach ($this->tracking->inspectorIdsScheduledBetween($start, $end) as $inspectorId) {
            $results[] = $this->evaluateInspectorMonth($inspectorId, $start);
        }

        return $results;
    }

    /** @return array<string,mixed> */
    public function evaluateInspectorMonth(int $inspectorId, DateTimeImmutable $monthStart): array
    {
        $start = $this->firstOfMonth($monthStart);
        $end = $start->modify('+1 month');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $missed = $this->countMissed($inspectorId, $start, $end, $now);

        $previous = $this->tracking->forInspectorMonth($inspectorId, $start->modify('-1 month'));
        $previousStreak = $previous !== null ? (int) $previous['consecutive_miss_count'] : 0;
        $consecutive = $missed > 0 ? $previousStreak + 1 : 0;

        return $this->tracking->upsert(
            $inspectorId,
            $start,
            $missed,
            $consecutive,
            $consecutive >= $this->strikeThreshold,
            $now,
        );
    }

    private function countMissed(int $inspectorId, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM inspections
              WHERE inspector_id = :insp
                AND scheduled_at >= :start AND scheduled_at < :end
                AND status IN ('scheduled', 'in_progress')
                AND DATE_ADD(scheduled_at, INTERVAL :grace HOUR) < :now"
        );
        $stmt->execute([
            'insp'  => $inspectorId,
            'start' => $start->format('Y-m-d H:i:s'),
            'end'   => $end->format('Y-m-d H:i:s'),
            'grace' => $this->graceHours,
            'now'   => $now->format('Y-m-d H:i:s'),
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function firstOfMonth(DateTimeImmutable $d): DateTimeImmutable
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0, 0);
    }
}
