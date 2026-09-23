<?php

declare(strict_types=1);

namespace App\Tests\Documents;

use App\Documents\TradeDetailsRepository;
use App\Tests\Support\DatabaseTestCase;

final class TradeDetailsRepositoryTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['consignment_trade_details', 'consignments', 'clients'];
    }

    public function testUnknownConsignmentHasNoRow(): void
    {
        self::assertNull((new TradeDetailsRepository($this->pdo))->forConsignment(999999));
    }

    public function testUpsertInsertsThenUpdatesTheWholeRow(): void
    {
        $client = $this->makeClientRow();
        $cons = $this->makeConsignmentRow($client['id']);
        $repo = new TradeDetailsRepository($this->pdo);

        $repo->upsert($cons['id'], [
            'importer_name' => 'Acme Importers',
            'nepc_number'   => '0001127',
            'basis_of_sale' => 'CIF',
        ]);

        $row = $repo->forConsignment($cons['id']);
        self::assertSame('Acme Importers', $row['importer_name']);
        self::assertSame('0001127', $row['nepc_number']);
        self::assertSame('CIF', $row['basis_of_sale']);
        self::assertNull($row['invoice_number']); // untouched column stays null

        // Re-upsert replaces the whole row (a column dropped from the payload goes back to null).
        $repo->upsert($cons['id'], ['invoice_number' => 'INV-1']);
        $row = $repo->forConsignment($cons['id']);
        self::assertSame('INV-1', $row['invoice_number']);
        self::assertNull($row['importer_name']);
    }
}
