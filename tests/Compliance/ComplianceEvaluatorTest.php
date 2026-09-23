<?php

declare(strict_types=1);

namespace App\Tests\Compliance;

use App\Compliance\ComplianceEvaluator;
use App\Compliance\ComplianceRepository;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;

final class ComplianceEvaluatorTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['compliance_tracking', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function evaluator(int $graceHours = 24, int $threshold = 3): ComplianceEvaluator
    {
        return new ComplianceEvaluator($this->pdo, new ComplianceRepository($this->pdo), $graceHours, $threshold);
    }

    /** @return array{inspector:array, office:array, client:array, cons:array} */
    private function baseFixtures(): array
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $office = $this->makeUser(['role' => 'office_reviewer']);
        $client = $this->makeClientRow();
        $cons = $this->makeConsignmentRow($client['id']);

        return ['inspector' => $inspector, 'office' => $office, 'client' => $client, 'cons' => $cons];
    }

    private function scheduleInspection(array $f, DateTimeImmutable $scheduledAt, string $status = 'scheduled'): array
    {
        $req = $this->makeInspectionRequestRow($f['cons']['id'], $f['office']['id']);

        return $this->makeScheduledInspectionRow($req['id'], $f['inspector']['id'], [
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            'status'       => $status,
        ]);
    }

    private function currentMonthStart(DateTimeImmutable $actualNow): DateTimeImmutable
    {
        return $actualNow->modify('first day of this month')->setTime(0, 0, 0);
    }

    /**
     * A timestamp that (a) falls inside the given month and (b) is safely in
     * the past relative to the real wall clock, so a short grace period has
     * definitely elapsed — even when `$monthStart` is the current month and
     * "now" is only minutes into it. Avoids two edge cases a fixed "-N hours"
     * offset would hit on the 1st/2nd of a month: landing in the wrong
     * month bucket, or landing in the future relative to the real clock.
     */
    private function safeTimestampIn(DateTimeImmutable $monthStart, DateTimeImmutable $actualNow): DateTimeImmutable
    {
        $candidate = $monthStart->modify('+2 hours');
        $ceiling = $actualNow->modify('-2 hours');

        return $candidate > $ceiling ? $ceiling : $candidate;
    }

    public function testAnInspectionPastGraceAndStillScheduledCountsAsMissed(): void
    {
        $f = $this->baseFixtures();
        $actualNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $monthStart = $this->currentMonthStart($actualNow);

        // Past a 1h grace, still 'scheduled'.
        $this->scheduleInspection($f, $this->safeTimestampIn($monthStart, $actualNow), 'scheduled');

        $result = $this->evaluator(graceHours: 1)->evaluateInspectorMonth((int) $f['inspector']['id'], $monthStart);

        self::assertSame(1, $result['missed_windows_count']);
        self::assertSame(1, $result['consecutive_miss_count']);
        self::assertFalse((bool) $result['alert_triggered']); // raw DB row: MySQL BOOLEAN comes back as "0"/"1", not a real bool
    }

    public function testAnInspectionWithinGraceIsNotYetMissed(): void
    {
        $f = $this->baseFixtures();
        $actualNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Scheduled 1 minute ago, well within the default 24h grace.
        $this->scheduleInspection($f, $actualNow->modify('-1 minute'), 'scheduled');

        $result = $this->evaluator()->evaluateInspectorMonth((int) $f['inspector']['id'], $actualNow);

        self::assertSame(0, $result['missed_windows_count']);
        self::assertSame(0, $result['consecutive_miss_count']);
    }

    public function testASyncedInspectionIsNeverMissedEvenLate(): void
    {
        $f = $this->baseFixtures();
        $actualNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $monthStart = $this->currentMonthStart($actualNow);

        // Well past grace, but the inspector got it to 'synced' -> not missed.
        $this->scheduleInspection($f, $this->safeTimestampIn($monthStart, $actualNow), 'synced');

        $result = $this->evaluator(graceHours: 1)->evaluateInspectorMonth((int) $f['inspector']['id'], $monthStart);

        self::assertSame(0, $result['missed_windows_count']);
    }

    public function testConsecutiveMissedMonthsBuildAStreakAndTriggerAtThreshold(): void
    {
        $f = $this->baseFixtures();
        $actualNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentMonthStart = $this->currentMonthStart($actualNow);
        $evaluator = $this->evaluator(graceHours: 1);

        // Build 3 consecutive months, each with one missed inspection.
        $result = null;
        foreach ([-2, -1, 0] as $offset) {
            $monthStart = $currentMonthStart->modify("{$offset} months");
            $this->scheduleInspection($f, $this->safeTimestampIn($monthStart, $actualNow), 'scheduled');
            $result = $evaluator->evaluateInspectorMonth((int) $f['inspector']['id'], $monthStart);
        }

        self::assertSame(3, $result['consecutive_miss_count']);
        self::assertTrue((bool) $result['alert_triggered']);
    }

    public function testACompliantMonthResetsTheStreak(): void
    {
        $f = $this->baseFixtures();
        $actualNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentMonthStart = $this->currentMonthStart($actualNow);
        $lastMonthStart = $currentMonthStart->modify('-1 month');
        $evaluator = $this->evaluator(graceHours: 1);

        $this->scheduleInspection($f, $this->safeTimestampIn($lastMonthStart, $actualNow), 'scheduled'); // missed
        $evaluator->evaluateInspectorMonth((int) $f['inspector']['id'], $lastMonthStart);

        $this->scheduleInspection($f, $this->safeTimestampIn($currentMonthStart, $actualNow), 'synced'); // compliant this month
        $result = $evaluator->evaluateInspectorMonth((int) $f['inspector']['id'], $currentMonthStart);

        self::assertSame(0, $result['missed_windows_count']);
        self::assertSame(0, $result['consecutive_miss_count']);
    }

    public function testEvaluateMonthOnlyTouchesInspectorsWithSomethingScheduledThatMonth(): void
    {
        $f = $this->baseFixtures();
        $idle = $this->makeUser(['role' => 'inspector']); // nothing ever scheduled
        $actualNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $monthStart = $this->currentMonthStart($actualNow);

        $this->scheduleInspection($f, $this->safeTimestampIn($monthStart, $actualNow), 'scheduled');

        $results = $this->evaluator(graceHours: 1)->evaluateMonth($monthStart);

        $touched = array_column($results, 'inspector_uuid');
        self::assertContains($f['inspector']['uuid'], $touched);
        self::assertNotContains($idle['uuid'], $touched);

        $repo = new ComplianceRepository($this->pdo);
        self::assertNull($repo->forInspectorMonth((int) $idle['id'], $monthStart));
    }

    public function testEvaluateIsIdempotentReRunningDoesNotDuplicateRows(): void
    {
        $f = $this->baseFixtures();
        $actualNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $monthStart = $this->currentMonthStart($actualNow);
        $this->scheduleInspection($f, $this->safeTimestampIn($monthStart, $actualNow), 'scheduled');

        $evaluator = $this->evaluator(graceHours: 1);
        $evaluator->evaluateInspectorMonth((int) $f['inspector']['id'], $monthStart);
        $evaluator->evaluateInspectorMonth((int) $f['inspector']['id'], $monthStart);

        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM compliance_tracking')->fetchColumn(),
        );
    }
}
