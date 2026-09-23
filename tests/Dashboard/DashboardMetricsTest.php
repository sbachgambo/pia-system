<?php

declare(strict_types=1);

namespace App\Tests\Dashboard;

use App\Dashboard\DashboardMetrics;
use App\Tests\Support\DatabaseTestCase;

final class DashboardMetricsTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    public function testMonthlySeriesIsZeroFilledOldestFirstAndCountsThisMonth(): void
    {
        $insp = $this->makeUser(['role' => 'inspector']);
        $cons = $this->makeConsignmentRow($this->makeClientRow()['id']);
        $req = $this->makeInspectionRequestRow($cons['id'], $insp['id']);
        $this->makeScheduledInspectionRow($req['id'], $insp['id']);
        $this->makeScheduledInspectionRow($req['id'], $insp['id']);

        $m = (new DashboardMetrics($this->pdo))->monthlyInspections(6);

        self::assertCount(6, $m);
        self::assertSame(gmdate('M'), $m[5]['label']);
        self::assertSame(2, $m[5]['scheduled']);
        self::assertSame(0, $m[0]['scheduled']);
        self::assertSame(0, $m[5]['finalized']);
    }

    public function testRequestStatusCountsAlwaysListEveryStatus(): void
    {
        $c = (new DashboardMetrics($this->pdo))->requestStatusCounts();

        self::assertSame(['pending', 'scheduled', 'completed', 'cancelled'], array_keys($c));
        self::assertSame(0, array_sum($c));
    }
}
