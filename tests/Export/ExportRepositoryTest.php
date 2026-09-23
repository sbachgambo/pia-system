<?php

declare(strict_types=1);

namespace App\Tests\Export;

use App\Export\ExportRepository;
use App\Tests\Support\DatabaseTestCase;

final class ExportRepositoryTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    public function testClientsExportHonoursFiltersAndStringifiesNulls(): void
    {
        $this->makeClientRow(['name' => 'Exporter One', 'type' => 'exporter']);
        $this->makeClientRow(['name' => 'Importer One', 'type' => 'importer']);
        $repo = new ExportRepository($this->pdo);

        $all = $repo->clients([]);
        $onlyImporters = $repo->clients(['type' => 'importer']);
        $searched = $repo->clients(['q' => 'Exporter']);

        self::assertCount(2, $all['rows']);
        self::assertCount(1, $onlyImporters['rows']);
        self::assertSame('Importer One', $onlyImporters['rows'][0][1]);
        self::assertCount(1, $searched['rows']);
        self::assertSame('', $searched['rows'][0][3], 'NULL rc_number exports as an empty cell');
        self::assertCount(count($all['headers']), $all['rows'][0]);
    }

    public function testUsersExportNeverContainsCredentialColumns(): void
    {
        $this->makeUser(['full_name' => 'Ada Test', 'role' => 'admin']);
        $out = (new ExportRepository($this->pdo))->users([]);

        self::assertNotContains('Password', $out['headers']);
        self::assertCount(1, $out['rows']);
        foreach ($out['rows'][0] as $cell) {
            self::assertStringNotContainsString('$argon2', $cell);
        }
    }

    public function testInspectionsExportDefaultsToTheReviewQueueStatuses(): void
    {
        $insp = $this->makeUser(['role' => 'inspector']);
        $cons = $this->makeConsignmentRow($this->makeClientRow()['id']);
        $req = $this->makeInspectionRequestRow($cons['id'], $insp['id']);
        $this->makeScheduledInspectionRow($req['id'], $insp['id'], ['status' => 'scheduled']);
        $this->makeScheduledInspectionRow($req['id'], $insp['id'], ['status' => 'synced']);
        $repo = new ExportRepository($this->pdo);

        self::assertCount(1, $repo->inspections([])['rows']);
        self::assertCount(1, $repo->inspections(['status' => 'scheduled'])['rows']);
        self::assertCount(1, $repo->inspections(['status' => 'bogus'])['rows'], 'unknown status falls back to the default set');
    }
}
