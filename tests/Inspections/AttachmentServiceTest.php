<?php

declare(strict_types=1);

namespace App\Tests\Inspections;

use App\Inspections\AttachmentRepository;
use App\Inspections\AttachmentService;
use App\Inspections\AttachmentStorage;
use App\Inspections\FindingRepository;
use App\Inspections\InspectionRepository;
use App\Inspections\SyncLogRepository;
use App\Inspections\SyncService;
use App\Office\NotFoundException;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use Ramsey\Uuid\Uuid;

final class AttachmentServiceTest extends DatabaseTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    private string $storeDir;

    protected function dirtyTables(): array
    {
        return ['sync_log', 'inspection_attachments', 'inspection_findings', 'inspections',
                'inspection_requests', 'consignments', 'clients', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeDir = sys_get_temp_dir() . '/pia_att_svc_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storeDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->storeDir)) {
            rmdir($this->storeDir);
        }
    }

    private function service(): AttachmentService
    {
        return new AttachmentService(
            new AttachmentRepository($this->pdo),
            new AttachmentStorage($this->storeDir, 1_000_000),
            new SyncLogRepository($this->pdo),
        );
    }

    /**
     * Build a scenario and declare one attachment via a real sync.
     *
     * @return array{inspector:int, other_inspector:int, office_role:string, attachment_uuid:string, attachment_id:int}
     */
    private function declaredAttachment(string $inspectionStatus = 'scheduled'): array
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $other     = $this->makeUser(['role' => 'inspector']);
        $office    = $this->makeUser(['role' => 'office_reviewer']);
        $client    = $this->makeClientRow();
        $cons      = $this->makeConsignmentRow($client['id']);
        $req       = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'scheduled']);
        $insp      = $this->makeScheduledInspectionRow($req['id'], $inspector['id'], ['status' => $inspectionStatus]);

        $attUuid = Uuid::uuid4()->toString();

        if (in_array($inspectionStatus, ['scheduled', 'in_progress', 'synced', 'amended'], true)) {
            $sync = new SyncService(
                $this->pdo,
                new InspectionRepository($this->pdo),
                new FindingRepository($this->pdo),
                new AttachmentRepository($this->pdo),
                new SyncLogRepository($this->pdo),
            );
            $sync->sync(['inspections' => [[
                'uuid' => $insp['uuid'], 'status' => 'synced', 'findings' => [],
                'attachments' => [[
                    'client_uuid' => $attUuid, 'file_type' => 'photo',
                    'captured_at' => '2026-09-09T07:00:00+00:00', 'checksum_sha256' => hash('sha256', self::PNG),
                ]],
            ]]], $inspector['id'], null);
        } else {
            // finalized: insert the stub directly
            $this->pdo->prepare(
                'INSERT INTO inspection_attachments (inspection_id, client_uuid, file_type, captured_at, checksum_sha256, created_at, updated_at)
                 VALUES (:i, :u, "photo", UTC_TIMESTAMP(), :s, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            )->execute(['i' => $insp['id'], 'u' => $attUuid, 's' => hash('sha256', self::PNG)]);
        }

        $id = (int) $this->pdo->query('SELECT id FROM inspection_attachments WHERE client_uuid = ' . $this->pdo->quote($attUuid))->fetchColumn();

        return [
            'inspector'       => $inspector['id'],
            'other_inspector' => $other['id'],
            'office_role'     => 'office_reviewer',
            'attachment_uuid' => $attUuid,
            'attachment_id'   => $id,
        ];
    }

    public function testUploadStoresTheBlobAndMarksTheRow(): void
    {
        $s = $this->declaredAttachment();

        $meta = $this->service()->upload($s['attachment_uuid'], $s['inspector'], self::PNG, hash('sha256', self::PNG), 'seal.png', 'dev-1');

        self::assertSame('image/png', $meta['mime_type']);

        $row = $this->pdo->query('SELECT file_path, uploaded_at, mime_type FROM inspection_attachments')->fetch();
        self::assertNotNull($row['file_path']);
        self::assertNotNull($row['uploaded_at']);
        self::assertSame('image/png', $row['mime_type']);
        self::assertSame('success', $this->pdo->query("SELECT sync_status FROM sync_log WHERE record_type='attachment' LIMIT 1")->fetchColumn());
    }

    public function testUploadForUndeclaredAttachmentIs409(): void
    {
        $this->declaredAttachment();
        $this->expectException(ApiException::class);
        $this->service()->upload(Uuid::uuid4()->toString(), 1, self::PNG, hash('sha256', self::PNG), null, null);
    }

    public function testUploadByWrongInspectorIs404(): void
    {
        $s = $this->declaredAttachment();
        $this->expectException(NotFoundException::class);
        $this->service()->upload($s['attachment_uuid'], $s['other_inspector'], self::PNG, hash('sha256', self::PNG), null, null);
    }

    public function testUploadOnFinalizedInspectionIs409(): void
    {
        $s = $this->declaredAttachment('finalized');
        try {
            $this->service()->upload($s['attachment_uuid'], $s['inspector'], self::PNG, hash('sha256', self::PNG), null, null);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('inspection_locked', $e->getErrorCode());
        }
    }

    public function testDownloadByAssignedInspectorAndByOfficeButNotByOtherInspector(): void
    {
        $s = $this->declaredAttachment();
        $this->service()->upload($s['attachment_uuid'], $s['inspector'], self::PNG, hash('sha256', self::PNG), 'x.png', null);

        $asInspector = $this->service()->download($s['attachment_id'], 'inspector', $s['inspector']);
        self::assertSame(self::PNG, $asInspector['bytes']);
        self::assertStringEndsWith('.png', $asInspector['filename']);

        $asOffice = $this->service()->download($s['attachment_id'], 'office_reviewer', 999999);
        self::assertSame(self::PNG, $asOffice['bytes']);

        $this->expectException(NotFoundException::class);
        $this->service()->download($s['attachment_id'], 'inspector', $s['other_inspector']);
    }

    public function testDownloadBeforeUploadIs409(): void
    {
        $s = $this->declaredAttachment();
        try {
            $this->service()->download($s['attachment_id'], 'office_reviewer', 1);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('attachment_pending', $e->getErrorCode());
        }
    }
}
