<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * End-to-end HTTP tests for /api/office/* — auth gate, role gate, and the
 * client -> consignment -> inspection-request happy path.
 */
final class OfficeEndpointTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'rate_limits', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function request(string $method, string $path, ?array $json = null, ?string $token = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($json)));
        }

        return AppFactory::create()->handle($request);
    }

    private function decode(ResponseInterface $r): array
    {
        return json_decode((string) $r->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Log in a freshly-made user of the given role and return the access token. */
    private function tokenFor(string $role): string
    {
        $user = $this->makeUser(['role' => $role, 'password' => 'Office-Pass-1']);
        $res = $this->request('POST', '/api/auth/login', ['email' => $user['email'], 'password' => 'Office-Pass-1']);
        self::assertSame(200, $res->getStatusCode());

        return $this->decode($res)['access_token'];
    }

    public function testNoTokenIsRejected(): void
    {
        $res = $this->request('GET', '/api/office/clients');
        self::assertSame(401, $res->getStatusCode());
        self::assertSame('invalid_token', $this->decode($res)['error']['code']);
    }

    public function testInspectorRoleIsForbidden(): void
    {
        $token = $this->tokenFor('inspector');

        $res = $this->request('GET', '/api/office/clients', null, $token);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('forbidden', $this->decode($res)['error']['code']);
    }

    public function testOfficeReviewerCanRunTheFullIntakeChain(): void
    {
        $token = $this->tokenFor('office_reviewer');

        // 1. client
        $client = $this->decode($this->request('POST', '/api/office/clients', [
            'name' => 'Groundnut Co', 'type' => 'exporter', 'address' => '5 Kano Road',
            'contact_name' => 'Musa', 'contact_phone' => '+2348011112222', 'contact_email' => 'musa@gn.test',
        ], $token));
        self::assertArrayHasKey('uuid', $client);

        // 2. consignment
        $consResp = $this->request('POST', '/api/office/consignments', [
            'client_uuid' => $client['uuid'], 'direction' => 'export',
            'product_category' => 'Groundnuts', 'product_description' => 'Raw shelled',
            'quantity' => 2000, 'unit_of_measure' => 'kg', 'declared_value' => 90000,
            'currency' => 'USD', 'origin_country' => 'Nigeria', 'destination_country' => 'India', 'zone' => 'Kano',
            'form_nxp_number' => 'NXP-E2E-0001',
        ], $token);
        self::assertSame(201, $consResp->getStatusCode());
        $cons = $this->decode($consResp);
        self::assertSame($client['uuid'], $cons['client_uuid']);

        // 3. inspection request
        $irResp = $this->request('POST', '/api/office/inspection-requests', [
            'consignment_uuid' => $cons['uuid'], 'requested_at' => '2026-09-20T08:00:00+00:00',
        ], $token);
        self::assertSame(201, $irResp->getStatusCode());
        $ir = $this->decode($irResp);
        self::assertSame('pending', $ir['status']);
        self::assertSame('2026-09-23T08:00:00+00:00', $ir['notice_deadline']); // +72h default

        // 4. transition pending -> scheduled
        $t = $this->request('POST', "/api/office/inspection-requests/{$ir['uuid']}/transition", ['status' => 'scheduled'], $token);
        self::assertSame(200, $t->getStatusCode());
        self::assertSame('scheduled', $this->decode($t)['status']);

        // 5. it shows up in the list
        $list = $this->decode($this->request('GET', '/api/office/inspection-requests?status=scheduled', null, $token));
        self::assertSame(1, $list['pagination']['total']);
    }

    public function testValidationErrorShape(): void
    {
        $token = $this->tokenFor('admin');

        $res = $this->request('POST', '/api/office/clients', ['name' => 'X'], $token);

        self::assertSame(422, $res->getStatusCode());
        $body = $this->decode($res);
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertArrayHasKey('field', $body['error']['details']);
    }

    public function testUnknownClientUuidOnConsignmentIs422(): void
    {
        $token = $this->tokenFor('admin');

        $res = $this->request('POST', '/api/office/consignments', [
            'client_uuid' => '00000000-0000-0000-0000-000000000000', 'direction' => 'export',
            'product_category' => 'X', 'product_description' => 'Y', 'quantity' => 1, 'unit_of_measure' => 'kg',
            'declared_value' => 1, 'currency' => 'USD', 'origin_country' => 'A', 'destination_country' => 'B', 'zone' => 'Z',
        ], $token);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame('client_uuid', $this->decode($res)['error']['details']['field']);
    }
}
