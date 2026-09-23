<?php

declare(strict_types=1);

namespace App\Tests\Office;

use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Office\ClientRepository;
use App\Office\ConsignmentRepository;
use App\Office\ConsignmentService;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ConsignmentServiceTest extends DatabaseTestCase
{
    private int $nxpSeq = 0;

    protected function dirtyTables(): array
    {
        return ['documents', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function service(): ConsignmentService
    {
        return new ConsignmentService(new ConsignmentRepository($this->pdo), new ClientRepository($this->pdo));
    }

    /** A valid create payload; exports get a fresh, unique NXP number unless the test sets one. */
    private function valid(string $clientUuid, array $overrides = []): Input
    {
        if (!array_key_exists('form_nxp_number', $overrides) && ($overrides['direction'] ?? 'export') === 'export') {
            $overrides['form_nxp_number'] = 'NXP-T-' . ++$this->nxpSeq;
        }

        return Input::fromArray($overrides + [
            'client_uuid'         => $clientUuid,
            'direction'           => 'export',
            'product_category'    => 'Cocoa',
            'product_description' => 'Dried beans, grade A',
            'quantity'            => 1500,
            'unit_of_measure'     => 'kg',
            'declared_value'      => 82000.5,
            'currency'            => 'usd',
            'origin_country'      => 'Nigeria',
            'destination_country' => 'Germany',
            'zone'                => 'Lagos',
        ]);
    }

    public function testCreateResolvesClientAndNormalises(): void
    {
        $client = $this->makeClientRow();

        $c = $this->service()->create($this->valid($client['uuid']));

        self::assertSame($client['uuid'], $c['client_uuid']);
        self::assertSame('USD', $c['currency']);            // upper-cased
        self::assertSame('82000.50', $c['declared_value']); // 2dp string, precise
        self::assertSame('1500.00', $c['quantity']);
        self::assertArrayNotHasKey('id', $c);
    }

    public function testUnknownClientUuidIs422NotFoundField(): void
    {
        try {
            $this->service()->create($this->valid('00000000-0000-0000-0000-000000000000'));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('client_uuid', $e->getDetails()['field']);
        }
    }

    public function testNxpNumberRejectedOnImport(): void
    {
        $client = $this->makeClientRow(['type' => 'importer']);

        try {
            $this->service()->create($this->valid($client['uuid'], [
                'direction'      => 'import',
                'form_nxp_number' => 'NXP-123',
            ]));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('validation_failed', $e->getErrorCode());
            self::assertSame('form_nxp_number', $e->getDetails()['field']);
        }
    }

    public function testNxpNumberAllowedOnExport(): void
    {
        $client = $this->makeClientRow();

        $c = $this->service()->create($this->valid($client['uuid'], ['form_nxp_number' => 'NXP-777']));

        self::assertSame('NXP-777', $c['form_nxp_number']);
    }

    public function testUpdateChangingDirectionToImportWithExistingNxpIsRejected(): void
    {
        $client = $this->makeClientRow();
        $c = $this->service()->create($this->valid($client['uuid'], ['form_nxp_number' => 'NXP-1']));

        $this->expectException(ApiException::class);
        $this->service()->update($c['uuid'], Input::fromArray(['direction' => 'import']));
    }

    public function testListFiltersByDirectionAndClient(): void
    {
        $c1 = $this->makeClientRow();
        $c2 = $this->makeClientRow();
        $this->service()->create($this->valid($c1['uuid'], ['direction' => 'export']));
        $this->service()->create($this->valid($c1['uuid'], ['direction' => 'import', 'form_nxp_number' => null]));
        $this->service()->create($this->valid($c2['uuid'], ['direction' => 'export']));

        $page = Pagination::fromRequest((new ServerRequestFactory())->createServerRequest('GET', '/x'));

        $forC1Exports = $this->service()->list($page, ['client_uuid' => $c1['uuid'], 'direction' => 'export']);

        self::assertSame(1, $forC1Exports['pagination']['total']);
        self::assertSame($c1['uuid'], $forC1Exports['data'][0]['client_uuid']);
        self::assertSame('export', $forC1Exports['data'][0]['direction']);
    }

    public function testAnExportMustHaveAnNxpNumber(): void
    {
        $client = $this->makeClientRow();

        try {
            $this->service()->create($this->valid($client['uuid'], ['form_nxp_number' => null]));
            self::fail('an export without an NXP number should be refused');
        } catch (ApiException $e) {
            self::assertSame('form_nxp_number', $e->getDetails()['field']);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM consignments')->fetchColumn());
        }
    }

    public function testNxpNumbersAreUniqueIgnoringCaseAndSpaces(): void
    {
        $client = $this->makeClientRow();
        $this->service()->create($this->valid($client['uuid'], ['form_nxp_number' => 'XG2026000111']));

        try {
            $this->service()->create($this->valid($client['uuid'], ['form_nxp_number' => '  xg2026000111 ']));
            self::fail('a duplicate NXP number should be refused');
        } catch (ApiException $e) {
            self::assertSame('form_nxp_number', $e->getDetails()['field']);
            self::assertStringContainsString('already recorded', $e->getMessage());
        }
    }

    public function testTheDatabaseAlsoRefusesADuplicateNxpNumber(): void
    {
        // Belt and braces: two requests racing past the service check still
        // cannot both land, because the column has a unique index.
        $client = $this->makeClientRow();
        $this->makeConsignmentRow($client['id'], ['form_nxp_number' => 'RACE-1']);

        $this->expectException(\PDOException::class);
        $this->makeConsignmentRow($client['id'], ['form_nxp_number' => 'race-1']);
    }

    public function testSavingARecordKeepsItsOwnNxpNumberWithoutTrippingTheDuplicateCheck(): void
    {
        $client = $this->makeClientRow();
        $c = $this->service()->create($this->valid($client['uuid'], ['form_nxp_number' => 'OWN-1']));

        $updated = $this->service()->update($c['uuid'], Input::fromArray(['zone' => 'Kano', 'form_nxp_number' => 'OWN-1']));

        self::assertSame('Kano', $updated['zone']);
        self::assertSame('OWN-1', $updated['form_nxp_number']);
    }

    public function testALegacyExportWithoutAnNxpNumberMustBeGivenOneWhenEdited(): void
    {
        // Saved before the rule (inserted directly, as old rows were).
        $client = $this->makeClientRow();
        $legacy = $this->makeConsignmentRow($client['id'], ['direction' => 'export', 'form_nxp_number' => null]);

        try {
            $this->service()->update($legacy['uuid'], Input::fromArray(['zone' => 'Kano']));
            self::fail('editing a legacy export without adding its NXP number should be refused');
        } catch (ApiException $e) {
            self::assertSame('form_nxp_number', $e->getDetails()['field']);
        }

        $fixed = $this->service()->update($legacy['uuid'], Input::fromArray(['zone' => 'Kano', 'form_nxp_number' => 'LEGACY-1']));
        self::assertSame('LEGACY-1', $fixed['form_nxp_number']);
    }

    public function testMissingNxpFilterFindsOnlyExportsWithoutANumber(): void
    {
        $client = $this->makeClientRow();
        $this->makeConsignmentRow($client['id'], ['direction' => 'export', 'form_nxp_number' => null, 'product_category' => 'Legacy']);
        $this->makeConsignmentRow($client['id'], ['direction' => 'import', 'form_nxp_number' => null]);
        $this->service()->create($this->valid($client['uuid']));

        $page = Pagination::fromRequest((new ServerRequestFactory())->createServerRequest('GET', '/x'));
        $missing = $this->service()->list($page, ['missing_nxp' => true]);

        self::assertSame(1, $missing['pagination']['total']);
        self::assertSame('Legacy', $missing['data'][0]['product_category']);
    }

    public function testSearchMatchesTheNxpNumberAndClientName(): void
    {
        $client = $this->makeClientRow(['name' => 'Zebra Exports Ltd']);
        $this->service()->create($this->valid($client['uuid'], ['form_nxp_number' => 'FINDME-42']));
        $page = Pagination::fromRequest((new ServerRequestFactory())->createServerRequest('GET', '/x'));

        self::assertSame(1, $this->service()->list($page, ['q' => 'findme'])['pagination']['total']);
        self::assertSame(1, $this->service()->list($page, ['q' => 'Zebra'])['pagination']['total']);
    }

    public function testHistoryListsEveryCertificateIssuedUnderTheNxp(): void
    {
        $client = $this->makeClientRow();
        $cons = $this->makeConsignmentRow($client['id'], ['form_nxp_number' => 'HIST-1']);
        $staff = $this->makeUser(['role' => 'office_reviewer']);
        $inspector = $this->makeUser(['role' => 'inspector']);
        $req = $this->makeInspectionRequestRow($cons['id'], $staff['id']);
        $insp = $this->makeScheduledInspectionRow($req['id'], $inspector['id'], ['status' => 'finalized']);
        $this->makeDocumentRow($insp['id'], ['issued_by' => $staff['id']]);
        $this->makeInspectionRequestRow($cons['id'], $staff['id'], ['status' => 'pending']); // no inspection yet

        $history = (new ConsignmentRepository($this->pdo))->history($cons['id']);

        self::assertCount(2, $history, 'one row per request, including the one not yet inspected');
        self::assertCount(1, array_filter(array_column($history, 'document_number')));
    }
}
