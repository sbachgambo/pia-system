<?php

declare(strict_types=1);

namespace App\Tests\Office;

use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Office\ClientRepository;
use App\Office\ClientService;
use App\Office\NotFoundException;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ClientServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['inspection_requests', 'consignments', 'clients'];
    }

    private function service(): ClientService
    {
        return new ClientService(new ClientRepository($this->pdo));
    }

    private function valid(array $overrides = []): Input
    {
        return Input::fromArray($overrides + [
            'name'          => 'Palm Exports Ltd',
            'type'          => 'exporter',
            'rc_number'     => 'RC99887',
            'address'       => '12 Wharf Road, Apapa',
            'contact_name'  => 'Ada Obi',
            'contact_phone' => '+2348012345678',
            'contact_email' => 'ada@palm.test',
        ]);
    }

    public function testCreateReturnsRepresentationWithoutInternalId(): void
    {
        $client = $this->service()->create($this->valid());

        self::assertArrayNotHasKey('id', $client);
        self::assertSame('Palm Exports Ltd', $client['name']);
        self::assertSame('exporter', $client['type']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $client['uuid']);
        self::assertNotNull($client['created_at']);
    }

    public function testCreateRejectsBadType(): void
    {
        $this->expectException(ApiException::class);
        $this->service()->create($this->valid(['type' => 'wholesaler']));
    }

    public function testCreateRejectsBadEmail(): void
    {
        try {
            $this->service()->create($this->valid(['contact_email' => 'not-an-email']));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('validation_failed', $e->getErrorCode());
            self::assertSame('contact_email', $e->getDetails()['field']);
        }
    }

    public function testGetUnknownUuidIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->get('00000000-0000-0000-0000-000000000000');
    }

    public function testPartialUpdateOnlyChangesGivenFields(): void
    {
        $created = $this->service()->create($this->valid());

        $updated = $this->service()->update($created['uuid'], Input::fromArray(['contact_phone' => '+2340000000001']));

        self::assertSame('+2340000000001', $updated['contact_phone']);
        self::assertSame('Palm Exports Ltd', $updated['name']); // untouched
    }

    public function testListPaginatesAndFiltersByType(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->service()->create($this->valid(['name' => "Exp {$i}", 'type' => 'exporter', 'contact_email' => "e{$i}@x.test"]));
        }
        $this->service()->create($this->valid(['name' => 'Imp A', 'type' => 'importer', 'contact_email' => 'i@x.test']));

        // Slim's ServerRequestFactory does not parse the query string into
        // getQueryParams() (production goes through createFromGlobals which
        // does); set it explicitly here.
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x')
            ->withQueryParams(['per_page' => '2', 'page' => '1']);
        $page = Pagination::fromRequest($request);

        $exporters = $this->service()->list($page, ['type' => 'exporter']);

        self::assertCount(2, $exporters['data']);
        self::assertSame(3, $exporters['pagination']['total']);
        self::assertSame(2, $exporters['pagination']['total_pages']);
        foreach ($exporters['data'] as $row) {
            self::assertSame('exporter', $row['type']);
        }
    }
}
