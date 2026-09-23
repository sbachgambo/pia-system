<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\CalendarEvents;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;

final class CalendarEventsTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function utc(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    public function testGroupsInspectionsAndPendingDeadlinesByDayAndRespectsTheWindow(): void
    {
        $insp = $this->makeUser(['role' => 'inspector', 'full_name' => 'Ada Insp']);
        $client = $this->makeClientRow(['name' => 'Cal Client']);
        $cons = $this->makeConsignmentRow($client['id'], ['product_category' => 'Ginger']);
        $req = $this->makeInspectionRequestRow($cons['id'], $insp['id']);
        $this->makeScheduledInspectionRow($req['id'], $insp['id'], ['scheduled_at' => '2026-03-10 09:30:00']);
        $this->makeScheduledInspectionRow($req['id'], $insp['id'], ['scheduled_at' => '2026-04-02 09:30:00']);
        $this->pdo->exec("UPDATE inspection_requests SET notice_deadline = '2026-03-10 17:00:00', status = 'pending'");

        $byDay = (new CalendarEvents($this->pdo))->between($this->utc('2026-03-01'), $this->utc('2026-04-01'));

        self::assertSame(['2026-03-10'], array_keys($byDay));
        self::assertSame(['inspection', 'deadline'], array_column($byDay['2026-03-10'], 'kind'), 'sorted by time');
        self::assertSame('Cal Client', $byDay['2026-03-10'][0]['client']);
        self::assertSame('Ada Insp', $byDay['2026-03-10'][0]['inspector']);
    }

    public function testInspectorFilterNarrowsInspectionsAndHidesUnassignedDeadlines(): void
    {
        $a = $this->makeUser(['role' => 'inspector']);
        $b = $this->makeUser(['role' => 'inspector']);
        $cons = $this->makeConsignmentRow($this->makeClientRow()['id']);
        $req = $this->makeInspectionRequestRow($cons['id'], $a['id']);
        $this->makeScheduledInspectionRow($req['id'], $a['id'], ['scheduled_at' => '2026-03-10 09:00:00']);
        $this->makeScheduledInspectionRow($req['id'], $b['id'], ['scheduled_at' => '2026-03-10 11:00:00']);
        $this->pdo->exec("UPDATE inspection_requests SET notice_deadline = '2026-03-10 17:00:00', status = 'pending'");

        $only = (new CalendarEvents($this->pdo))->between($this->utc('2026-03-01'), $this->utc('2026-04-01'), $a['uuid']);

        self::assertCount(1, $only['2026-03-10']);
        self::assertSame('inspection', $only['2026-03-10'][0]['kind']);
    }

    public function testInspectorsListContainsOnlyInspectors(): void
    {
        $this->makeUser(['role' => 'inspector', 'full_name' => 'Only Insp']);
        $this->makeUser(['role' => 'admin', 'full_name' => 'Not Insp']);

        self::assertSame(['Only Insp'], array_column((new CalendarEvents($this->pdo))->inspectors(), 'name'));
    }
}
