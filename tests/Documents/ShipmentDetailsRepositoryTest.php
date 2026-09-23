<?php

declare(strict_types=1);

namespace App\Tests\Documents;

use App\Documents\ShipmentDetailsRepository;
use App\Tests\Support\DatabaseTestCase;

final class ShipmentDetailsRepositoryTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['inspection_shipment_details', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    public function testUnknownInspectionHasNoRow(): void
    {
        self::assertNull((new ShipmentDetailsRepository($this->pdo))->forInspection(999999));
    }

    public function testUpsertInsertsThenUpdatesTheWholeRow(): void
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $office = $this->makeUser(['role' => 'office_reviewer']);
        $client = $this->makeClientRow();
        $cons = $this->makeConsignmentRow($client['id']);
        $req = $this->makeInspectionRequestRow($cons['id'], $office['id']);
        $insp = $this->makeScheduledInspectionRow($req['id'], $inspector['id']);

        $repo = new ShipmentDetailsRepository($this->pdo);
        $repo->upsert($insp['id'], [
            'shipping_agent'  => 'Allied Marine',
            'gross_weight_kg' => '15279.00',
            'ness_receipt_no' => '026328',
        ]);

        $row = $repo->forInspection($insp['id']);
        self::assertSame('Allied Marine', $row['shipping_agent']);
        self::assertSame('15279.00', $row['gross_weight_kg']);
        self::assertSame('026328', $row['ness_receipt_no']);
        self::assertNull($row['net_weight_kg']);

        $repo->upsert($insp['id'], ['net_weight_kg' => '13890.00']);
        $row = $repo->forInspection($insp['id']);
        self::assertSame('13890.00', $row['net_weight_kg']);
        self::assertNull($row['shipping_agent']); // whole-row replace
    }
}
