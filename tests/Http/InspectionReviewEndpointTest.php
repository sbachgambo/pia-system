<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * HTTP for the Phase 5 office review workflow: queue -> detail -> amend ->
 * finalize, plus reject and the role gates. Builds on the Phase 4 sync path.
 */
final class InspectionReviewEndpointTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'rate_limits', 'audit_log', 'sync_log', 'inspection_attachments',
                'inspection_findings', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
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
        $u = $this->makeUser(['role' => $role, 'password' => 'Review-Pass-1']);
        $res = $this->req('POST', '/api/auth/login', ['email' => $u['email'], 'password' => 'Review-Pass-1']);

        return ['token' => $this->decode($res)['access_token'], 'uuid' => $u['uuid'], 'id' => $u['id']];
    }

    /** office schedules + inspector syncs -> returns the synced inspection uuid */
    private function syncedInspection(array $office, array $inspector): string
    {
        $client = $this->makeClientRow();
        $cons   = $this->makeConsignmentRow($client['id']);
        $rq     = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'pending']);

        $sched = $this->req('POST', "/api/office/inspection-requests/{$rq['uuid']}/schedule", [
            'inspector_uuid' => $inspector['uuid'], 'location_type' => 'warehouse', 'location_detail' => 'Bay 1',
        ], $office['token']);
        $uuid = $this->decode($sched)['uuid'];

        $this->req('POST', '/api/inspections/sync', ['inspections' => [[
            'uuid' => $uuid, 'status' => 'synced',
            'findings' => [['checklist_item' => 'Marks', 'result' => 'pass']],
            'attachments' => [],
        ]]], $inspector['token']);

        return $uuid;
    }

    public function testQueueDetailAmendFinalize(): void
    {
        $office    = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');
        $uuid = $this->syncedInspection($office, $inspector);

        // queue
        $queue = $this->decode($this->req('GET', '/api/office/inspections', null, $office['token']));
        self::assertSame(1, $queue['pagination']['total']);
        self::assertSame($uuid, $queue['data'][0]['uuid']);

        // detail with audit trail
        $detail = $this->decode($this->req('GET', "/api/inspections/{$uuid}", null, $office['token']));
        self::assertSame('synced', $detail['status']);
        self::assertArrayHasKey('audit_trail', $detail);
        self::assertCount(1, $detail['findings']);

        // amend
        $amended = $this->req('POST', "/api/inspections/{$uuid}/amend", [
            'findings' => [
                ['checklist_item' => 'Marks', 'result' => 'pass'],
                ['checklist_item' => 'Weight', 'result' => 'flag', 'notes' => 'reweigh'],
            ],
        ], $office['token']);
        self::assertSame(200, $amended->getStatusCode());
        $body = $this->decode($amended);
        self::assertSame('amended', $body['status']);
        self::assertCount(2, $body['findings']);
        self::assertSame('inspection.amend', $body['audit_trail'][0]['action']);

        // finalize
        $final = $this->req('POST', "/api/inspections/{$uuid}/finalize", null, $office['token']);
        self::assertSame(200, $final->getStatusCode());
        self::assertSame('finalized', $this->decode($final)['status']);

        // a late field sync is now rejected as locked
        $late = $this->req('POST', '/api/inspections/sync', ['inspections' => [[
            'uuid' => $uuid, 'status' => 'synced', 'findings' => [], 'attachments' => [],
        ]]], $inspector['token']);
        self::assertSame('rejected', $this->decode($late)['results'][0]['status']);
        self::assertSame('locked', $this->decode($late)['results'][0]['reason']);
    }

    public function testRejectFlow(): void
    {
        $office    = $this->tokenFor('admin');
        $inspector = $this->tokenFor('inspector');
        $uuid = $this->syncedInspection($office, $inspector);

        // reject needs a reason
        self::assertSame(422, $this->req('POST', "/api/inspections/{$uuid}/reject", [], $office['token'])->getStatusCode());

        $rej = $this->req('POST', "/api/inspections/{$uuid}/reject", ['reason' => 'blurry photos'], $office['token']);
        self::assertSame(200, $rej->getStatusCode());
        self::assertSame('rejected', $this->decode($rej)['status']);

        // inspector re-syncs the correction
        $resync = $this->req('POST', '/api/inspections/sync', ['inspections' => [[
            'uuid' => $uuid, 'status' => 'synced', 'findings' => [['checklist_item' => 'Marks', 'result' => 'pass']], 'attachments' => [],
        ]]], $inspector['token']);
        self::assertSame('accepted', $this->decode($resync)['results'][0]['status']);
        self::assertSame('synced', $this->decode($resync)['results'][0]['inspection_status']);
    }

    public function testRoleGates(): void
    {
        $office    = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');
        $uuid = $this->syncedInspection($office, $inspector);

        // inspector cannot review
        self::assertSame(403, $this->req('GET', "/api/inspections/{$uuid}", null, $inspector['token'])->getStatusCode());
        self::assertSame(403, $this->req('POST', "/api/inspections/{$uuid}/amend", ['findings' => []], $inspector['token'])->getStatusCode());
        self::assertSame(403, $this->req('POST', "/api/inspections/{$uuid}/finalize", null, $inspector['token'])->getStatusCode());
        self::assertSame(403, $this->req('GET', '/api/office/inspections', null, $inspector['token'])->getStatusCode());

        // no token
        self::assertSame(401, $this->req('GET', "/api/inspections/{$uuid}")->getStatusCode());
    }
}
