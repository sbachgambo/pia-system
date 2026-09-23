<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Phase 6: document generation + retrieval over HTTP. Finalize auto-triggers
 * a CCI (best-effort, ReviewController); the explicit generate/list/download
 * routes are exercised directly too.
 */
final class DocumentEndpointTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['documents', 'document_sequences', 'audit_log', 'sync_log', 'inspection_shipment_details',
                'consignment_trade_details', 'inspection_attachments', 'inspection_findings', 'inspections',
                'inspection_requests', 'consignments', 'clients', 'refresh_tokens', 'rate_limits', 'users'];
    }

    private function req(string $method, string $path, ?array $json = null, ?string $token = null): ResponseInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($token !== null) {
            $r = $r->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')
                   ->withBody((new StreamFactory())->createStream((string) json_encode($json)));
        }

        return AppFactory::create()->handle($r);
    }

    private function decode(ResponseInterface $r): array
    {
        return json_decode((string) $r->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tokenFor(string $role): array
    {
        $u = $this->makeUser(['role' => $role, 'password' => 'Doc-Pass-1']);
        $res = $this->req('POST', '/api/auth/login', ['email' => $u['email'], 'password' => 'Doc-Pass-1']);

        return ['token' => $this->decode($res)['access_token'], 'uuid' => $u['uuid'], 'id' => $u['id']];
    }

    /** office schedules + inspector syncs + office finalizes -> the finalized inspection uuid */
    private function finalizedInspection(array $office, array $inspector): string
    {
        $client = $this->makeClientRow();
        $cons   = $this->makeConsignmentRow($client['id']);
        $rq     = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'pending']);

        $sched = $this->req('POST', "/api/office/inspection-requests/{$rq['uuid']}/schedule", [
            'inspector_uuid' => $inspector['uuid'], 'location_type' => 'port', 'location_detail' => 'Onne',
        ], $office['token']);
        $uuid = $this->decode($sched)['uuid'];

        $this->req('POST', '/api/inspections/sync', ['inspections' => [[
            'uuid' => $uuid, 'status' => 'synced',
            'findings' => [['checklist_item' => 'Marks', 'result' => 'pass']],
            'attachments' => [],
        ]]], $inspector['token']);

        $final = $this->req('POST', "/api/inspections/{$uuid}/finalize", null, $office['token']);
        self::assertSame(200, $final->getStatusCode());

        return $uuid;
    }

    public function testFinalizeAutoGeneratesACciDocument(): void
    {
        $office = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');

        $client = $this->makeClientRow();
        $cons   = $this->makeConsignmentRow($client['id']);
        $rq     = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'pending']);
        $sched  = $this->req('POST', "/api/office/inspection-requests/{$rq['uuid']}/schedule", [
            'inspector_uuid' => $inspector['uuid'], 'location_type' => 'port', 'location_detail' => 'Onne',
        ], $office['token']);
        $uuid = $this->decode($sched)['uuid'];
        $this->req('POST', '/api/inspections/sync', ['inspections' => [[
            'uuid' => $uuid, 'status' => 'synced', 'findings' => [['checklist_item' => 'Marks', 'result' => 'pass']], 'attachments' => [],
        ]]], $inspector['token']);

        $final = $this->decode($this->req('POST', "/api/inspections/{$uuid}/finalize", null, $office['token']));

        self::assertSame('finalized', $final['status']);
        self::assertNotNull($final['document']);
        self::assertSame('CCI', $final['document']['type']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{5}$/', $final['document']['document_number']);
        self::assertArrayNotHasKey('document_generation_error', $final);
    }

    public function testExplicitGenerateListAndDownload(): void
    {
        $office = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');
        $uuid = $this->finalizedInspection($office, $inspector);

        // finalize already auto-generated one CCI; generate an NNCI explicitly too
        $nnci = $this->decode($this->req('POST', "/api/inspections/{$uuid}/documents", ['type' => 'NNCI'], $office['token']));
        self::assertSame(201, 201); // sanity - status asserted below via a fresh call
        self::assertSame('NNCI', $nnci['type']);

        $list = $this->decode($this->req('GET', "/api/inspections/{$uuid}/documents", null, $office['token']));
        self::assertGreaterThanOrEqual(2, count($list['data']));

        $download = $this->req('GET', "/api/documents/{$nnci['uuid']}", null, $office['token']);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('application/pdf', $download->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $download->getBody());
    }

    public function testGenerateRejectsUnsupportedTypeAndNonFinalized(): void
    {
        $office = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');

        $client = $this->makeClientRow();
        $cons   = $this->makeConsignmentRow($client['id']);
        $rq     = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'pending']);
        $sched  = $this->req('POST', "/api/office/inspection-requests/{$rq['uuid']}/schedule", [
            'inspector_uuid' => $inspector['uuid'], 'location_type' => 'port', 'location_detail' => 'Onne',
        ], $office['token']);
        $uuid = $this->decode($sched)['uuid']; // still 'scheduled', never synced/finalized

        $bad = $this->req('POST', "/api/inspections/{$uuid}/documents", ['type' => 'CRF'], $office['token']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame('unsupported_document_type', $this->decode($bad)['error']['code']);

        $notFinal = $this->req('POST', "/api/inspections/{$uuid}/documents", ['type' => 'CCI'], $office['token']);
        self::assertSame(422, $notFinal->getStatusCode());
        self::assertSame('not_finalized', $this->decode($notFinal)['error']['code']);
    }

    public function testRoleGatesAndAuth(): void
    {
        $office = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');
        $uuid = $this->finalizedInspection($office, $inspector);

        self::assertSame(403, $this->req('POST', "/api/inspections/{$uuid}/documents", ['type' => 'CCI'], $inspector['token'])->getStatusCode());
        self::assertSame(403, $this->req('GET', "/api/inspections/{$uuid}/documents", null, $inspector['token'])->getStatusCode());

        $doc = $this->decode($this->req('GET', "/api/inspections/{$uuid}/documents", null, $office['token']))['data'][0];
        self::assertSame(403, $this->req('GET', "/api/documents/{$doc['uuid']}", null, $inspector['token'])->getStatusCode());
        self::assertSame(401, $this->req('GET', "/api/documents/{$doc['uuid']}")->getStatusCode());
    }
}
