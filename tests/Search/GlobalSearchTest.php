<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Search\GlobalSearch;
use App\Tests\Support\DatabaseTestCase;

final class GlobalSearchTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['documents', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    public function testFindsClientsConsignmentsAndInspectionsByClientName(): void
    {
        $insp = $this->makeUser(['role' => 'inspector']);
        $client = $this->makeClientRow(['name' => 'Zebra Agro Ltd']);
        $cons = $this->makeConsignmentRow($client['id'], ['product_category' => 'Sesame']);
        $req = $this->makeInspectionRequestRow($cons['id'], $insp['id']);
        $this->makeScheduledInspectionRow($req['id'], $insp['id']);

        $r = (new GlobalSearch($this->pdo))->search('Zebra', false);

        self::assertCount(1, $r['clients']);
        self::assertCount(1, $r['consignments']);
        self::assertSame('Zebra Agro Ltd', $r['consignments'][0]['client_name']);
        self::assertCount(1, $r['inspections']);
        self::assertSame([], $r['users']);
    }

    public function testUsersOnlyReturnedWhenRequested(): void
    {
        $this->makeUser(['full_name' => 'Amaka Findme']);
        $search = new GlobalSearch($this->pdo);

        self::assertSame([], $search->search('Findme', false)['users']);
        self::assertCount(1, $search->search('Findme', true)['users']);
    }

    public function testTooShortQueryReturnsNothing(): void
    {
        $this->makeClientRow(['name' => 'Xylo']);

        self::assertSame([], (new GlobalSearch($this->pdo))->search('X', true)['clients']);
    }

    public function testLikeWildcardsAreMatchedLiterally(): void
    {
        $this->makeClientRow(['name' => 'Alpha Co']);
        $this->makeClientRow(['name' => '50% Off Traders']);
        $search = new GlobalSearch($this->pdo);

        self::assertCount(1, $search->search('50%', false)['clients']);
        self::assertSame([], $search->search('%%', false)['clients']);
        self::assertSame([], $search->search('A_pha', false)['clients']);
    }
}
