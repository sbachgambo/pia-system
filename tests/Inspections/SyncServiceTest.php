<?php

declare(strict_types=1);

namespace App\Tests\Inspections;

use App\Inspections\AttachmentRepository;
use App\Inspections\FindingRepository;
use App\Inspections\InspectionRepository;
use App\Inspections\SyncLogRepository;
use App\Inspections\SyncService;
use App\Tests\Support\DatabaseTestCase;
use Ramsey\Uuid\Uuid;

final class SyncServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['sync_log', 'inspection_attachments', 'inspection_findings', 'inspections',
                'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function service(): SyncService
    {
        return new SyncService(
            $this->pdo,
            new InspectionRepository($this->pdo),
            new FindingRepository($this->pdo),
            new AttachmentRepository($this->pdo),
            new SyncLogRepository($this->pdo),
        );
    }

    /** @return array{inspector:int, inspection_uuid:string} */
    private function scenario(string $status = 'scheduled'): array
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $office    = $this->makeUser(['role' => 'office_reviewer']);
        $client    = $this->makeClientRow();
        $cons      = $this->makeConsignmentRow($client['id']);
        $req       = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'scheduled']);
        $insp      = $this->makeScheduledInspectionRow($req['id'], $inspector['id'], ['status' => $status]);

        return ['inspector' => $inspector['id'], 'inspection_uuid' => $insp['uuid']];
    }

    private function payload(string $uuid, array $overrides = []): array
    {
        return ['inspections' => [array_merge([
            'uuid'        => $uuid,
            'status'      => 'synced',
            'started_at'  => '2026-09-09T07:00:00+00:00',
            'findings'    => [
                ['checklist_item' => 'Seals intact', 'result' => 'pass', 'observed_value' => 'Yes'],
                ['checklist_item' => 'Weight matches', 'result' => 'flag', 'notes' => 'Under by 2%'],
            ],
            'attachments' => [
                ['client_uuid' => Uuid::uuid4()->toString(), 'file_type' => 'photo',
                 'captured_at' => '2026-09-09T07:05:00+00:00', 'checksum_sha256' => str_repeat('a', 64)],
            ],
        ], $overrides)]];
    }

    public function testSyncAcceptsAndStoresFindingsAndAttachmentStubs(): void
    {
        $s = $this->scenario();

        $out = $this->service()->sync($this->payload($s['inspection_uuid']), $s['inspector'], 'device-7');

        self::assertCount(1, $out['results']);
        self::assertSame('accepted', $out['results'][0]['status']);
        self::assertSame('synced', $out['results'][0]['inspection_status']);
        self::assertCount(1, $out['results'][0]['attachments_pending']); // no blob yet

        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM inspection_findings')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inspection_attachments')->fetchColumn());
        self::assertSame('success', $this->pdo->query('SELECT sync_status FROM sync_log ORDER BY id DESC LIMIT 1')->fetchColumn());

        $insp = $this->pdo->query('SELECT status, started_at, synced_at, device_id FROM inspections')->fetch();
        self::assertSame('synced', $insp['status']);
        self::assertNotNull($insp['started_at']);
        self::assertNotNull($insp['synced_at']);
        self::assertSame('device-7', $insp['device_id']);
    }

    public function testResendingTheSameBatchIsIdempotent(): void
    {
        $s = $this->scenario();
        $payload = $this->payload($s['inspection_uuid']);

        $this->service()->sync($payload, $s['inspector'], null);
        $this->service()->sync($payload, $s['inspector'], null);

        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM inspection_findings')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inspection_attachments')->fetchColumn());
    }

    public function testUnknownUuidIsRejectedAsNotAssigned(): void
    {
        $s = $this->scenario();

        $out = $this->service()->sync($this->payload(Uuid::uuid4()->toString()), $s['inspector'], null);

        self::assertSame('rejected', $out['results'][0]['status']);
        self::assertSame('not_assigned', $out['results'][0]['reason']);
        self::assertSame('conflict', $this->pdo->query('SELECT sync_status FROM sync_log LIMIT 1')->fetchColumn());
    }

    public function testAnotherInspectorsInspectionIsNotAssigned(): void
    {
        $s = $this->scenario();
        $other = $this->makeUser(['role' => 'inspector']);

        $out = $this->service()->sync($this->payload($s['inspection_uuid']), $other['id'], null);

        self::assertSame('not_assigned', $out['results'][0]['reason']);
    }

    public function testFinalizedInspectionIsLocked(): void
    {
        $s = $this->scenario('finalized');

        $out = $this->service()->sync($this->payload($s['inspection_uuid']), $s['inspector'], null);

        self::assertSame('rejected', $out['results'][0]['status']);
        self::assertSame('locked', $out['results'][0]['reason']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inspection_findings')->fetchColumn());
    }

    public function testInvalidFindingResultIsRejectedWithoutPartialWrite(): void
    {
        $s = $this->scenario();
        $bad = $this->payload($s['inspection_uuid'], [
            'findings' => [['checklist_item' => 'X', 'result' => 'maybe']],
        ]);

        $out = $this->service()->sync($bad, $s['inspector'], null);

        self::assertSame('rejected', $out['results'][0]['status']);
        self::assertSame('validation', $out['results'][0]['reason']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inspection_findings')->fetchColumn());
        self::assertSame('error', $this->pdo->query('SELECT sync_status FROM sync_log LIMIT 1')->fetchColumn());
    }

    public function testBatchTooLargeIsRejected(): void
    {
        $this->expectExceptionMessage('50 inspections');
        $this->service()->sync(['inspections' => array_fill(0, 51, ['uuid' => Uuid::uuid4()->toString()])], 1, null);
    }
}
