<?php

declare(strict_types=1);

namespace App\Tests\Records;

use App\Inspections\AttachmentStorage;
use App\Office\ClientRepository;
use App\Office\ConsignmentRepository;
use App\Office\NotFoundException;
use App\Records\RecordAttachmentRepository;
use App\Records\RecordAttachmentService;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;

final class RecordAttachmentServiceTest extends DatabaseTestCase
{
    private string $dir;
    private RecordAttachmentService $svc;

    protected function dirtyTables(): array
    {
        return ['record_attachments', 'consignments', 'clients', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pia-rec-' . bin2hex(random_bytes(4));
        $this->svc = new RecordAttachmentService(
            new RecordAttachmentRepository($this->pdo),
            new AttachmentStorage($this->dir, 50_000),
            new ClientRepository($this->pdo),
            new ConsignmentRepository($this->pdo),
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** A minimal but genuine PNG (1x1). */
    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    public function testUploadListDownloadRoundTripPreservesBytesAndForcesTheRealExtension(): void
    {
        $user = $this->makeUser(['role' => 'office_reviewer']);
        $client = $this->makeClientRow();

        $row = $this->svc->add('client', $client['uuid'], $this->png(), 'scan.exe', $user['id']);

        self::assertSame('scan.png', $row['original_name']);
        self::assertSame('image/png', $row['mime_type']);
        self::assertCount(1, $this->svc->list('client', $client['uuid']));

        $file = $this->svc->download($row['uuid']);
        self::assertSame($this->png(), $file['bytes']);
        self::assertSame('scan.png', $file['filename']);
    }

    public function testDisallowedTypesAndOversizeAreRejectedAndNothingIsLeftBehind(): void
    {
        $user = $this->makeUser();
        $client = $this->makeClientRow();

        foreach (['plain text', str_repeat('x', 60_000)] as $bytes) {
            try {
                $this->svc->add('client', $client['uuid'], $bytes, 'a.txt', $user['id']);
                self::fail('expected rejection');
            } catch (ApiException $e) {
                self::assertContains($e->getStatusCode(), [413, 415]);
            }
        }
        self::assertSame([], $this->svc->list('client', $client['uuid']));
        self::assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    public function testDeleteRemovesRowAndFileAndUnknownRecordsAre404(): void
    {
        $user = $this->makeUser();
        $cons = $this->makeConsignmentRow($this->makeClientRow()['id']);
        $row = $this->svc->add('consignment', $cons['uuid'], $this->png(), 'p.png', $user['id']);

        $this->svc->delete($row['uuid']);

        self::assertSame([], $this->svc->list('consignment', $cons['uuid']));
        self::assertSame([], glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: []);

        $this->expectException(NotFoundException::class);
        $this->svc->add('client', '00000000-0000-0000-0000-000000000000', $this->png(), 'x.png', $user['id']);
    }

    public function testCleanNameStripsPathsControlCharsAndFallsBack(): void
    {
        self::assertSame('passwd.pdf', RecordAttachmentService::cleanName('../../etc/passwd', 'application/pdf'));
        self::assertSame('a b.jpg', RecordAttachmentService::cleanName("a\x00 b\";<.gif", 'image/jpeg'));
        self::assertSame('document.pdf', RecordAttachmentService::cleanName('', 'application/pdf'));
        self::assertSame('document.pdf', RecordAttachmentService::cleanName(null, 'application/pdf'));
    }
}
