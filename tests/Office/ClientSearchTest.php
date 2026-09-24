<?php

declare(strict_types=1);

namespace App\Tests\Office;

use App\Office\ClientRepository;
use App\Tests\Support\DatabaseTestCase;

/**
 * Regression cover for the client list's search box.
 *
 * The WHERE fragment matches three columns. Written with one shared named
 * placeholder it threw SQLSTATE[HY093] "Invalid parameter number" on every
 * search, because the app disables emulated prepares and MySQL then allows a
 * named parameter to appear only once per statement. Nothing exercised the
 * filtered path, so a fully green suite still shipped a 500 on
 * /console/clients?q=...
 */
final class ClientSearchTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['inspection_requests', 'consignments', 'clients'];
    }

    private function seed(): ClientRepository
    {
        $repo = new ClientRepository($this->pdo);

        $repo->create('11111111-1111-4111-8111-111111111111', [
            'name'          => 'Kano Grain Exporters',
            'type'          => 'exporter',
            'rc_number'     => 'RC1001',
            'address'       => '3 Market Road, Kano',
            'contact_name'  => 'Bala Musa',
            'contact_phone' => '+2348010000001',
            'contact_email' => 'bala@kanograin.test',
        ]);
        $repo->create('22222222-2222-4222-8222-222222222222', [
            'name'          => 'Lagos Cocoa Traders',
            'type'          => 'importer',
            'rc_number'     => 'RC1002',
            'address'       => '9 Wharf Road, Apapa',
            'contact_name'  => 'Ngozi Bala',
            'contact_phone' => '+2348010000002',
            'contact_email' => 'ngozi@lagoscocoa.test',
        ]);

        return $repo;
    }

    public function testSearchByNameDoesNotThrow(): void
    {
        $result = $this->seed()->paginate(25, 0, ['q' => 'Kano']);

        self::assertSame(1, $result['total']);
        self::assertSame('Kano Grain Exporters', $result['rows'][0]['name']);
    }

    public function testSearchMatchesContactNameAndEmailToo(): void
    {
        $repo = $this->seed();

        // 'Bala' is one row's contact name and part of the other's, so both
        // come back — proving every column in the OR is bound, not just the first.
        self::assertSame(2, $repo->paginate(25, 0, ['q' => 'Bala'])['total']);
        self::assertSame(1, $repo->paginate(25, 0, ['q' => 'lagoscocoa.test'])['total']);
    }

    public function testSearchCombinedWithTypeFilter(): void
    {
        $repo = $this->seed();

        self::assertSame(1, $repo->paginate(25, 0, ['q' => 'a', 'type' => 'exporter'])['total']);
        self::assertSame(0, $repo->paginate(25, 0, ['q' => 'Kano', 'type' => 'importer'])['total']);
    }

    public function testSearchWithNoMatchesReturnsEmpty(): void
    {
        $result = $this->seed()->paginate(25, 0, ['q' => 'no-such-client']);

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['rows']);
    }
}
