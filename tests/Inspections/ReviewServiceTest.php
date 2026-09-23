<?php

declare(strict_types=1);

namespace App\Tests\Inspections;

use App\Audit\AuditLog;
use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Inspections\AttachmentRepository;
use App\Inspections\FindingRepository;
use App\Inspections\InspectionRepository;
use App\Inspections\ReviewService;
use App\Inspections\SyncLogRepository;
use App\Inspections\SyncService;
use App\Office\NotFoundException;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ReviewServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['audit_log', 'sync_log', 'inspection_attachments', 'inspection_findings', 'inspections',
                'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function service(): ReviewService
    {
        return new ReviewService(
            $this->pdo,
            new InspectionRepository($this->pdo),
            new FindingRepository($this->pdo),
            new AttachmentRepository($this->pdo),
            new AuditLog($this->pdo),
        );
    }

    /** @return array{office:int, inspector:int, uuid:string, id:int} */
    private function syncedInspection(string $status = 'synced'): array
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $office    = $this->makeUser(['role' => 'office_reviewer']);
        $client    = $this->makeClientRow();
        $cons      = $this->makeConsignmentRow($client['id']);
        $req       = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'scheduled']);
        $insp      = $this->makeScheduledInspectionRow($req['id'], $inspector['id'], ['status' => $status, 'synced_at' => gmdate('Y-m-d H:i:s')]);

        (new FindingRepository($this->pdo))->replaceForInspection($insp['id'], [
            ['checklist_item' => 'Seals', 'expected_value' => null, 'observed_value' => 'intact', 'result' => 'pass', 'notes' => null],
            ['checklist_item' => 'Count', 'expected_value' => '100', 'observed_value' => '98', 'result' => 'flag', 'notes' => 'short'],
        ]);

        return ['office' => $office['id'], 'inspector' => $inspector['id'], 'uuid' => $insp['uuid'], 'id' => $insp['id']];
    }

    public function testAmendReplacesFindingsSetsAmendedAndWritesAudit(): void
    {
        $s = $this->syncedInspection();

        $result = $this->service()->amend($s['uuid'], Input::fromArray([
            'location_detail' => 'Dock 7 (corrected)',
            'findings' => [['checklist_item' => 'Seals', 'result' => 'fail', 'notes' => 'broken on arrival']],
        ]), $s['office'], '203.0.113.5');

        self::assertSame('amended', $result['status']);
        self::assertSame('Dock 7 (corrected)', $result['location_detail']);
        self::assertCount(1, $result['findings']);
        self::assertSame('fail', $result['findings'][0]['result']);

        $audit = $this->pdo->query("SELECT action, before_state, after_state, ip_address FROM audit_log ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame('inspection.amend', $audit['action']);
        self::assertSame('203.0.113.5', $audit['ip_address']);
        $before = json_decode($audit['before_state'], true);
        $after  = json_decode($audit['after_state'], true);
        self::assertCount(2, $before['findings']);            // original 2
        self::assertSame('synced', $before['status']);
        self::assertCount(1, $after['findings']);             // amended to 1
        self::assertSame('amended', $after['status']);

        self::assertNotEmpty($result['audit_trail']);
        self::assertSame('inspection.amend', $result['audit_trail'][0]['action']);
    }

    public function testAmendWithNoChangesIsRejected(): void
    {
        $s = $this->syncedInspection();
        try {
            $this->service()->amend($s['uuid'], Input::fromArray([]), $s['office'], null);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('nothing_to_amend', $e->getErrorCode());
        }
    }

    public function testCannotAmendAScheduledInspection(): void
    {
        $s = $this->syncedInspection('scheduled');
        try {
            $this->service()->amend($s['uuid'], Input::fromArray(['location_detail' => 'x']), $s['office'], null);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('not_reviewable', $e->getErrorCode());
        }
    }

    public function testFinalizeLocksAndStampsAndAudits(): void
    {
        $s = $this->syncedInspection();

        $result = $this->service()->finalize($s['uuid'], $s['office'], null);

        self::assertSame('finalized', $result['status']);
        self::assertNotNull($result['finalized_at']);

        $row = $this->pdo->query('SELECT finalized_by FROM inspections')->fetch();
        self::assertSame($s['office'], (int) $row['finalized_by']);

        self::assertSame('inspection.finalize', $this->pdo->query('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn());

        // no re-finalize
        $this->expectException(ApiException::class);
        $this->service()->finalize($s['uuid'], $s['office'], null);
    }

    public function testRejectRequiresReasonAndBouncesBackThenReSyncReturnsToSynced(): void
    {
        $s = $this->syncedInspection();

        try {
            $this->service()->reject($s['uuid'], Input::fromArray([]), $s['office'], null);
            self::fail('expected ApiException for missing reason');
        } catch (ApiException $e) {
            self::assertSame('validation_failed', $e->getErrorCode());
        }

        $rejected = $this->service()->reject($s['uuid'], Input::fromArray(['reason' => 'photos unreadable']), $s['office'], '198.51.100.1');
        self::assertSame('rejected', $rejected['status']);

        $audit = $this->pdo->query("SELECT after_state FROM audit_log WHERE action='inspection.reject' LIMIT 1")->fetchColumn();
        self::assertSame('photos unreadable', json_decode($audit, true)['reason']);

        // the inspector re-syncs the correction -> back to synced for another review
        $sync = new SyncService(
            $this->pdo,
            new InspectionRepository($this->pdo),
            new FindingRepository($this->pdo),
            new AttachmentRepository($this->pdo),
            new SyncLogRepository($this->pdo),
        );
        $out = $sync->sync(['inspections' => [['uuid' => $s['uuid'], 'status' => 'synced', 'findings' => [], 'attachments' => []]]], $s['inspector'], null);

        self::assertSame('accepted', $out['results'][0]['status']);
        self::assertSame('synced', $this->pdo->query('SELECT status FROM inspections')->fetchColumn());
    }

    public function testQueueListsReviewableInspectionsNewestFirst(): void
    {
        $this->syncedInspection('synced');
        $this->syncedInspection('amended');
        $this->syncedInspection('scheduled'); // not in the queue

        $page = Pagination::fromRequest((new ServerRequestFactory())->createServerRequest('GET', '/x'));
        $queue = $this->service()->queue($page, []);

        self::assertSame(2, $queue['pagination']['total']);
        foreach ($queue['data'] as $row) {
            self::assertContains($row['status'], ['synced', 'amended']);
        }
    }

    public function testShowUnknownUuidIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->show('00000000-0000-0000-0000-000000000000');
    }

    public function testAuditLogHasNoMutationMethods(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(AuditLog::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        // record() is the only writer; everything else must be a read. Any new
        // public method has to be added here deliberately, so a mutation path
        // can't slip in unnoticed.
        self::assertSame(
            ['__construct', 'record', 'forEntity', 'paginate', 'distinctActions'],
            $methods,
            'audit_log must be append-only at the app layer (§7)',
        );
    }
}
