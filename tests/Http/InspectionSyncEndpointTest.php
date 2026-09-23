<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * End-to-end HTTP for the Phase 4 field/sync surface:
 * office schedules -> inspector pulls /assigned -> POST /sync ->
 * upload the declared photo -> download it -> role gates.
 */
final class InspectionSyncEndpointTest extends DatabaseTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'rate_limits', 'sync_log', 'inspection_attachments', 'inspection_findings',
                'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function req(string $method, string $path, ?array $json = null, ?string $token = null, ?string $rawBody = null, array $headers = []): ResponseInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($token !== null) {
            $r = $r->withHeader('Authorization', 'Bearer ' . $token);
        }
        foreach ($headers as $k => $v) {
            $r = $r->withHeader($k, $v);
        }
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')
                   ->withBody((new StreamFactory())->createStream((string) json_encode($json)));
        } elseif ($rawBody !== null) {
            $r = $r->withBody((new StreamFactory())->createStream($rawBody));
        }

        return AppFactory::create()->handle($r);
    }

    private function decode(ResponseInterface $r): array
    {
        return json_decode((string) $r->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tokenFor(string $role): array
    {
        $u = $this->makeUser(['role' => $role, 'password' => 'Sync-Pass-1']);
        $res = $this->req('POST', '/api/auth/login', ['email' => $u['email'], 'password' => 'Sync-Pass-1']);
        self::assertSame(200, $res->getStatusCode());

        return ['token' => $this->decode($res)['access_token'], 'uuid' => $u['uuid'], 'id' => $u['id']];
    }

    public function testFullFieldRoundTrip(): void
    {
        $office    = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');

        $client = $this->makeClientRow();
        $cons   = $this->makeConsignmentRow($client['id']);
        $request = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'pending']);

        // 1. office schedules -> creates the inspection row
        $sched = $this->req('POST', "/api/office/inspection-requests/{$request['uuid']}/schedule", [
            'inspector_uuid'  => $inspector['uuid'],
            'location_type'   => 'warehouse',
            'location_detail' => 'Bay 9',
        ], $office['token']);
        self::assertSame(201, $sched->getStatusCode());
        $inspectionUuid = $this->decode($sched)['uuid'];

        // 2. inspector pulls the assigned set
        $assigned = $this->req('GET', '/api/inspections/assigned', null, $inspector['token']);
        self::assertSame(200, $assigned->getStatusCode());
        $body = $this->decode($assigned);
        self::assertCount(1, $body['inspections']);
        self::assertSame($inspectionUuid, $body['inspections'][0]['uuid']);
        self::assertSame($cons['uuid'], $body['inspections'][0]['consignment']['uuid']);

        // 3. sync findings + declare a photo
        $photoUuid = Uuid::uuid4()->toString();
        $sync = $this->req('POST', '/api/inspections/sync', ['inspections' => [[
            'uuid' => $inspectionUuid,
            'status' => 'synced',
            'started_at' => '2026-09-09T06:00:00+00:00',
            'findings' => [['checklist_item' => 'Marks & numbers', 'result' => 'pass']],
            'attachments' => [[
                'client_uuid' => $photoUuid, 'file_type' => 'photo',
                'captured_at' => '2026-09-09T06:10:00+00:00', 'checksum_sha256' => hash('sha256', self::PNG),
            ]],
        ]]], $inspector['token']);
        self::assertSame(200, $sync->getStatusCode());
        $sres = $this->decode($sync)['results'][0];
        self::assertSame('accepted', $sres['status']);
        self::assertContains($photoUuid, $sres['attachments_pending']);

        // re-sync -> idempotent
        $this->req('POST', '/api/inspections/sync', ['inspections' => [[
            'uuid' => $inspectionUuid, 'status' => 'synced', 'findings' => [], 'attachments' => [],
        ]]], $inspector['token']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inspections')->fetchColumn());

        // 4. upload the photo bytes
        $up = $this->req('POST', "/api/inspections/attachments/{$photoUuid}", null, $inspector['token'], self::PNG, [
            'X-Checksum-Sha256' => hash('sha256', self::PNG),
            'X-Original-Name'   => 'bay9.png',
        ]);
        self::assertSame(201, $up->getStatusCode());
        self::assertSame('image/png', $this->decode($up)['mime_type']);

        // 5. download it (as the inspector)
        $attId = (int) $this->pdo->query('SELECT id FROM inspection_attachments LIMIT 1')->fetchColumn();
        $dl = $this->req('GET', "/api/attachments/{$attId}", null, $inspector['token']);
        self::assertSame(200, $dl->getStatusCode());
        self::assertStringContainsString('image/png', $dl->getHeaderLine('Content-Type'));
        self::assertSame(self::PNG, (string) $dl->getBody());

        // office can download too
        $dlOffice = $this->req('GET', "/api/attachments/{$attId}", null, $office['token']);
        self::assertSame(200, $dlOffice->getStatusCode());
    }

    public function testRoleGates(): void
    {
        $office    = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');

        // office cannot use the field sync
        self::assertSame(403, $this->req('GET', '/api/inspections/assigned', null, $office['token'])->getStatusCode());
        self::assertSame(403, $this->req('POST', '/api/inspections/sync', ['inspections' => []], $office['token'])->getStatusCode());

        // inspector cannot use the office scheduling API
        $client = $this->makeClientRow();
        $cons   = $this->makeConsignmentRow($client['id']);
        $rq     = $this->makeInspectionRequestRow($cons['id'], $office['id']);
        self::assertSame(403, $this->req('POST', "/api/office/inspection-requests/{$rq['uuid']}/schedule", [
            'inspector_uuid' => $inspector['uuid'], 'location_type' => 'port', 'location_detail' => 'x',
        ], $inspector['token'])->getStatusCode());

        // no token at all
        self::assertSame(401, $this->req('GET', '/api/inspections/assigned')->getStatusCode());
    }
}
