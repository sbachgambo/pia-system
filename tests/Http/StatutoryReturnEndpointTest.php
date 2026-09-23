<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class StatutoryReturnEndpointTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['statutory_returns', 'documents', 'audit_log', 'sync_log', 'inspection_shipment_details',
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
        $u = $this->makeUser(['role' => $role, 'password' => 'Ret-Pass-1']);
        $res = $this->req('POST', '/api/auth/login', ['email' => $u['email'], 'password' => 'Ret-Pass-1']);

        return ['token' => $this->decode($res)['access_token'], 'uuid' => $u['uuid'], 'id' => $u['id']];
    }

    /** office schedules + inspector syncs + office finalizes -> issue a CCI directly (skip mPDF for test speed) */
    private function issueDocument(array $office, array $inspector, \DateTimeImmutable $issuedAt): void
    {
        $client = $this->makeClientRow();
        $cons = $this->makeConsignmentRow($client['id']);
        $rq = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'pending']);
        $sched = $this->req('POST', "/api/office/inspection-requests/{$rq['uuid']}/schedule", [
            'inspector_uuid' => $inspector['uuid'], 'location_type' => 'port', 'location_detail' => 'Onne',
        ], $office['token']);
        $uuid = $this->decode($sched)['uuid'];

        $this->req('POST', '/api/inspections/sync', ['inspections' => [[
            'uuid' => $uuid, 'status' => 'synced', 'findings' => [['checklist_item' => 'Marks', 'result' => 'pass']], 'attachments' => [],
        ]]], $inspector['token']);
        $this->req('POST', "/api/inspections/{$uuid}/finalize", null, $office['token']); // auto-generates a CCI too

        // A second, explicitly-dated document so the period filter has something predictable to select on.
        $inspId = (int) $this->pdo->query("SELECT id FROM inspections WHERE uuid = " . $this->pdo->quote($uuid))->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO documents (uuid, inspection_id, type, document_number, file_path, content_hash, hmac_signature, issued_at, status, created_at, updated_at)
             VALUES (:u, :i, 'CCI', :num, 'documents/x.pdf', :h, :s, :issued, 'issued', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            'u' => Uuid::uuid4()->toString(), 'i' => $inspId, 'num' => 'TEST-' . Uuid::uuid4()->toString(),
            'h' => str_repeat('a', 64), 's' => str_repeat('b', 64), 'issued' => $issuedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function testGenerateListSubmitDownload(): void
    {
        $admin = $this->tokenFor('admin');
        $office = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');
        $this->issueDocument($office, $inspector, new \DateTimeImmutable('2026-01-15'));

        $gen = $this->req('POST', '/api/statutory-returns/generate', [
            'agency' => 'CBN', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        ], $admin['token']);
        self::assertSame(201, $gen->getStatusCode());
        $doc = $this->decode($gen);
        self::assertSame('CBN', $doc['agency']);
        self::assertGreaterThanOrEqual(1, $doc['row_count']);
        self::assertSame('generated', $doc['status']);

        $list = $this->decode($this->req('GET', '/api/statutory-returns', null, $admin['token']));
        self::assertSame(1, $list['pagination']['total']);

        $download = $this->req('GET', "/api/statutory-returns/{$doc['uuid']}/download", null, $admin['token']);
        self::assertSame(200, $download->getStatusCode());
        self::assertStringContainsString('text/csv', $download->getHeaderLine('Content-Type'));
        self::assertStringContainsString('CCI No.', (string) $download->getBody());

        $submit = $this->decode($this->req('POST', "/api/statutory-returns/{$doc['uuid']}/submit", null, $admin['token']));
        self::assertSame('submitted', $submit['status']);

        $resubmit = $this->req('POST', "/api/statutory-returns/{$doc['uuid']}/submit", null, $admin['token']);
        self::assertSame(422, $resubmit->getStatusCode());
    }

    public function testRoleGatesAdminOnly(): void
    {
        $office = $this->tokenFor('office_reviewer');

        $gen = $this->req('POST', '/api/statutory-returns/generate', [
            'agency' => 'CBN', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        ], $office['token']);
        self::assertSame(403, $gen->getStatusCode());

        self::assertSame(403, $this->req('GET', '/api/statutory-returns', null, $office['token'])->getStatusCode());
        self::assertSame(401, $this->req('GET', '/api/statutory-returns')->getStatusCode());
    }

    public function testGenerateValidatesAgencyAndPeriod(): void
    {
        $admin = $this->tokenFor('admin');

        $badAgency = $this->req('POST', '/api/statutory-returns/generate', [
            'agency' => 'IRS', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
        ], $admin['token']);
        self::assertSame(422, $badAgency->getStatusCode());

        $missingDates = $this->req('POST', '/api/statutory-returns/generate', ['agency' => 'CBN'], $admin['token']);
        self::assertSame(422, $missingDates->getStatusCode());
    }
}
