<?php

declare(strict_types=1);

namespace App\Tests\Documents;

use App\Documents\CciDataAssembler;
use App\Documents\DocumentNumberAllocator;
use App\Documents\DocumentRepository;
use App\Documents\DocumentService;
use App\Documents\DocumentSigner;
use App\Documents\DocumentStorage;
use App\Documents\ShipmentDetailsRepository;
use App\Documents\TradeDetailsRepository;
use App\Http\View\Renderer;
use App\Inspections\InspectionRepository;
use App\Office\ClientRepository;
use App\Office\ConsignmentRepository;
use App\Office\NotFoundException;
use App\Settings\CompanySettingsRepository;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;

final class DocumentServiceTest extends DatabaseTestCase
{
    private const HMAC_KEY = 'test-hmac-key-not-for-production';

    protected function dirtyTables(): array
    {
        return ['documents', 'document_sequences', 'company_settings', 'inspection_shipment_details',
                'consignment_trade_details', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function service(): DocumentService
    {
        $settings = $this->settings();
        $tmp = sys_get_temp_dir() . '/pia_test_' . getmypid();

        return new DocumentService(
            new InspectionRepository($this->pdo),
            new CciDataAssembler(
                new InspectionRepository($this->pdo),
                new ConsignmentRepository($this->pdo),
                new ClientRepository($this->pdo),
                new TradeDetailsRepository($this->pdo),
                new ShipmentDetailsRepository($this->pdo),
            ),
            new DocumentNumberAllocator($this->pdo),
            new DocumentSigner(self::HMAC_KEY),
            new DocumentStorage($tmp . '/documents'),
            new DocumentRepository($this->pdo),
            new Renderer($settings['app']['base_path'] . '/templates', ['app_name' => $settings['app']['name']]),
            new \App\Documents\PdfRenderer($tmp . '/mpdf'),
            new CompanySettingsRepository($this->pdo, [
                'name' => 'Test PIA Co', 'address' => '1 Test Street, Testville', 'representative_title' => 'Authorised Representative',
            ]),
        );
    }

    /** @return array{uuid:string, id:int, consignment_id:int} */
    private function finalizedInspection(string $status = 'finalized'): array
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $office = $this->makeUser(['role' => 'office_reviewer']);
        $client = $this->makeClientRow(['name' => 'Tiger Foods Limited', 'rc_number' => '291714']);
        $cons = $this->makeConsignmentRow($client['id'], [
            'product_category' => 'Various Food Spices', 'quantity' => '8930.00', 'declared_value' => '10708.00',
            'currency' => 'USD', 'form_nxp_number' => 'XG20210007033042',
        ]);
        $req = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'scheduled']);
        $insp = $this->makeScheduledInspectionRow($req['id'], $inspector['id'], [
            'status' => $status,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'synced_at' => gmdate('Y-m-d H:i:s'),
        ]);

        if ($status === 'finalized') {
            $this->pdo->prepare("UPDATE inspections SET finalized_at = UTC_TIMESTAMP(), finalized_by = :by WHERE id = :id")
                ->execute(['by' => $office['id'], 'id' => $insp['id']]);
        }

        return ['uuid' => $insp['uuid'], 'id' => $insp['id'], 'consignment_id' => $cons['id']];
    }

    public function testGenerateRejectsANonFinalizedInspection(): void
    {
        $insp = $this->finalizedInspection('synced');

        try {
            $this->service()->generateForInspection($insp['uuid'], null, 'CCI');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('not_finalized', $e->getErrorCode());
        }
    }

    public function testGenerateRejectsAnUnsupportedType(): void
    {
        $insp = $this->finalizedInspection();

        try {
            $this->service()->generateForInspection($insp['uuid'], null, 'CRF');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('unsupported_document_type', $e->getErrorCode());
        }
    }

    public function testGenerateUnknownInspectionIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->generateForInspection('00000000-0000-0000-0000-000000000000', null, 'CCI');
    }

    public function testGenerateProducesAValidSignedPdfAndPersistsTheDocumentRow(): void
    {
        $insp = $this->finalizedInspection();
        $svc = $this->service();

        $doc = $svc->generateForInspection($insp['uuid'], null, 'CCI');

        self::assertSame('CCI', $doc['type']);
        self::assertSame('issued', $doc['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{5}$/', $doc['document_number']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $doc['content_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $doc['hmac_signature']);

        $file = $svc->download($doc['uuid']);
        self::assertStringStartsWith('%PDF', $file['bytes']);
        self::assertGreaterThan(1000, strlen($file['bytes']));

        // The signature verifies against the actual stored bytes with the same key.
        $signer = new DocumentSigner(self::HMAC_KEY);
        self::assertTrue($signer->verify($file['bytes'], $doc['content_hash'], $doc['hmac_signature']));
        self::assertFalse($signer->verify($file['bytes'] . 'x', $doc['content_hash'], $doc['hmac_signature']));
    }

    public function testGenerateNnciIsSupportedAndSeparatelyNumbered(): void
    {
        $insp = $this->finalizedInspection();
        $svc = $this->service();

        $cci = $svc->generateForInspection($insp['uuid'], null, 'CCI');
        $nnci = $svc->generateForInspection($insp['uuid'], null, 'NNCI');

        self::assertSame('NNCI', $nnci['type']);
        self::assertNotSame($cci['document_number'], $nnci['document_number']); // shared yearly sequence, not per-type
    }

    public function testListForInspectionReturnsGeneratedDocumentsNewestFirst(): void
    {
        $insp = $this->finalizedInspection();
        $svc = $this->service();

        $first = $svc->generateForInspection($insp['uuid'], null, 'CCI');
        $second = $svc->generateForInspection($insp['uuid'], null, 'CCI');

        $list = $svc->listForInspection($insp['uuid']);
        self::assertCount(2, $list);
        self::assertSame($second['uuid'], $list[0]['uuid']);
        self::assertSame($first['uuid'], $list[1]['uuid']);
    }

    public function testDownloadUnknownUuidIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->download('00000000-0000-0000-0000-000000000000');
    }

    /**
     * A certificate altered on disk after issue — by a doctored backup restore,
     * or anyone with file access — must not be handed out as authentic. The
     * signature used to be checked only by the archive export, so tampered
     * PDFs downloaded with HTTP 200 and no warning at all.
     */
    public function testDownloadRefusesAPdfThatNoLongerMatchesItsSignature(): void
    {
        $insp = $this->finalizedInspection();
        $svc = $this->service();
        $doc = $svc->generateForInspection($insp['uuid'], null, 'CCI');

        self::assertStringStartsWith('%PDF', $svc->download($doc['uuid'])['bytes']);

        $path = (string) $this->pdo
            ->query("SELECT file_path FROM documents WHERE uuid = '{$doc['uuid']}'")
            ->fetchColumn();
        // file_path is stored as "documents/<uuid>.pdf", while the file itself
        // lives directly in the storage base dir — hence basename() here.
        $onDisk = sys_get_temp_dir() . '/pia_test_' . getmypid() . '/documents/' . basename($path);
        self::assertFileExists($onDisk);
        file_put_contents($onDisk, file_get_contents($onDisk) . "\n% tampered after issue\n");

        try {
            $svc->download($doc['uuid']);
            self::fail('expected the tampered document to be refused');
        } catch (ApiException $e) {
            self::assertSame(409, $e->getStatusCode());
            self::assertSame('document_integrity_failed', $e->getErrorCode());
        }
    }
}
