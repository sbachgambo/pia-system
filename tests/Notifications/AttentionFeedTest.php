<?php

declare(strict_types=1);

namespace App\Tests\Notifications;

use App\Notifications\AttentionFeed;
use App\Tests\Support\DatabaseTestCase;

final class AttentionFeedTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['compliance_tracking', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    public function testCountsReflectLiveConditionsAndClearWhenResolved(): void
    {
        $insp = $this->makeUser(['role' => 'inspector']);
        $cons = $this->makeConsignmentRow($this->makeClientRow()['id']);
        $overdue = $this->makeInspectionRequestRow($cons['id'], $insp['id']);
        $soon = $this->makeInspectionRequestRow($cons['id'], $insp['id']);
        $this->pdo->exec("UPDATE inspection_requests SET notice_deadline = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE id = {$overdue['id']}");
        $this->pdo->exec("UPDATE inspection_requests SET notice_deadline = UTC_TIMESTAMP() + INTERVAL 5 HOUR WHERE id = {$soon['id']}");
        $this->makeScheduledInspectionRow($soon['id'], $insp['id'], ['status' => 'synced', 'synced_at' => gmdate('Y-m-d H:i:s')]);
        $feed = new AttentionFeed($this->pdo);

        $c = $feed->counts(false);
        self::assertSame(1, $c['review']);
        self::assertSame(1, $c['overdue']);
        self::assertSame(1, $c['due_soon']);
        self::assertSame(3, $c['total']);
        self::assertCount(1, $feed->items(false)['overdue']);

        $this->pdo->exec("UPDATE inspection_requests SET status = 'cancelled' WHERE id = {$overdue['id']}");
        $this->pdo->exec("UPDATE inspections SET status = 'finalized'");

        self::assertSame(1, $feed->counts(false)['total'], 'resolving the work clears the items');
    }

    public function testComplianceAlertsAreAdminOnly(): void
    {
        $insp = $this->makeUser(['role' => 'inspector']);
        $this->pdo->prepare(
            'INSERT INTO compliance_tracking (inspector_id, period_month, missed_windows_count, consecutive_miss_count, alert_triggered, last_evaluated_at, created_at, updated_at)
             VALUES (:i, :m, 2, 3, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute(['i' => $insp['id'], 'm' => gmdate('Y-m-01')]);
        $feed = new AttentionFeed($this->pdo);

        self::assertSame(0, $feed->counts(false)['alerts']);
        self::assertSame(1, $feed->counts(true)['alerts']);
        self::assertSame([], $feed->items(false)['alerts']);
        self::assertCount(1, $feed->items(true)['alerts']);
    }
}
