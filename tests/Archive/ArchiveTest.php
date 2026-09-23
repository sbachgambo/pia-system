<?php

declare(strict_types=1);

namespace App\Tests\Archive;

use App\Archive\ArchiveRepository;
use App\Archive\ArchiveService;
use App\Documents\DocumentSigner;
use App\Documents\DocumentStorage;
use App\Invoicing\InvoiceStorage;
use App\Returns\StatutoryReturnStorage;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use Ramsey\Uuid\Uuid;
use ZipArchive;

/**
 * The archive: one register over certificates, CBN invoices and statutory
 * returns — complete by construction, admin-only kinds hidden from reviewers,
 * and a bulk ZIP that never includes a file failing its signature.
 */
final class ArchiveTest extends DatabaseTestCase
{
    private const HMAC_KEY = 'test-hmac-key-for-archive-0123456789abcdef';

    private ArchiveRepository $repo;
    private ArchiveService $service;
    private DocumentSigner $signer;
    private string $root;
    private int $staffId;
    private int $inspectorId;

    protected function dirtyTables(): array
    {
        return ['cbn_invoices', 'statutory_returns', 'documents', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pia_archive_test_' . getmypid() . '_' . bin2hex(random_bytes(3));
        foreach (['documents', 'invoices', 'returns', 'cache'] as $d) {
            @mkdir($this->root . '/' . $d, 0775, true);
        }

        $this->signer = new DocumentSigner(self::HMAC_KEY);
        $this->repo = new ArchiveRepository($this->pdo);
        $this->service = new ArchiveService(
            $this->repo,
            new DocumentStorage($this->root . '/documents'),
            new InvoiceStorage($this->root . '/invoices'),
            new StatutoryReturnStorage($this->root . '/returns'),
            $this->signer,
            $this->root . '/cache',
        );
        $this->staffId = $this->makeUser(['role' => 'office_reviewer'])['id'];
        $this->inspectorId = $this->makeUser(['role' => 'inspector'])['id'];
    }

    /** A certificate with a real, signed file on disk. */
    private function certificate(string $clientName, string $nxp, string $number, string $type = 'CCI', string $status = 'issued', string $issuedAt = '2026-06-10 09:00:00'): string
    {
        $client = $this->makeClientRow(['name' => $clientName]);
        $cons = $this->makeConsignmentRow($client['id'], ['form_nxp_number' => $nxp]);
        $req = $this->makeInspectionRequestRow($cons['id'], $this->staffId);
        $insp = $this->makeScheduledInspectionRow($req['id'], $this->inspectorId, ['status' => 'finalized']);

        $uuid = Uuid::uuid4()->toString();
        $bytes = "%PDF-1.4 certificate {$number}";
        file_put_contents($this->root . '/documents/' . $uuid . '.pdf', $bytes);
        $signed = $this->signer->sign($bytes);
        $this->makeDocumentRow($insp['id'], [
            'uuid' => $uuid, 'type' => $type, 'status' => $status, 'document_number' => $number, 'issued_at' => $issuedAt,
            'file_path' => 'documents/' . $uuid . '.pdf', 'content_hash' => $signed['content_hash'], 'hmac_signature' => $signed['hmac_signature'],
        ]);

        return $uuid;
    }

    private function invoice(string $number, string $issuedAt = '2026-07-01 08:00:00'): string
    {
        $uuid = Uuid::uuid4()->toString();
        $bytes = "%PDF-1.4 invoice {$number}";
        file_put_contents($this->root . '/invoices/' . $uuid . '.pdf', $bytes);
        $signed = $this->signer->sign($bytes);
        $this->pdo->prepare(
            "INSERT INTO cbn_invoices (uuid, invoice_number, period_month, status, cci_count, fob_ngn, fee_rate, fee_ngn, `lines`,
                file_path, content_hash, hmac_signature, issued_at, created_at, updated_at)
             VALUES (:u, :n, '2026-06-01', 'issued', 1, 1000, 0.0035, 3.5, '[]', :p, :h, :s, :at, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute(['u' => $uuid, 'n' => $number, 'p' => 'invoices/' . $uuid . '.pdf', 'h' => $signed['content_hash'], 's' => $signed['hmac_signature'], 'at' => $issuedAt]);

        return $uuid;
    }

    private function statutoryReturn(): void
    {
        $uuid = Uuid::uuid4()->toString();
        file_put_contents($this->root . '/returns/' . $uuid . '.csv', "S/N,CCI No.\n1,2026-00001\n");
        $this->pdo->prepare(
            "INSERT INTO statutory_returns (uuid, agency, period_start, period_end, file_path, format, row_count, generated_at, status, created_at, updated_at)
             VALUES (:u, 'CBN', '2026-06-01', '2026-06-30', :p, 'csv', 1, '2026-07-02 10:00:00', 'generated', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute(['u' => $uuid, 'p' => 'returns/' . $uuid . '.csv']);
    }

    public function testEveryGeneratedDocumentIsInTheArchiveIncludingVoidedOnes(): void
    {
        $this->certificate('Tiger Foods', 'NXP-A', '2026-00001');
        $this->certificate('Tiger Foods', 'NXP-B', '2026-00002', 'NNCI');
        $this->certificate('Ilera Ltd', 'NXP-C', '2026-00003', 'CCI', 'void');
        $this->invoice('ADW/2026/001');
        $this->statutoryReturn();

        $all = $this->repo->search([], true, 50, 0);

        self::assertSame(5, $all['total']);
        self::assertEqualsCanonicalizing(['certificate', 'invoice', 'return'], array_values(array_unique(array_column($all['rows'], 'kind'))));
        self::assertContains('void', array_column($all['rows'], 'status'), 'a voided certificate stays on record');
    }

    public function testNonAdminsOnlyEverSeeCertificates(): void
    {
        $this->certificate('Tiger Foods', 'NXP-A', '2026-00001');
        $this->invoice('ADW/2026/001');
        $this->statutoryReturn();

        self::assertSame(['certificate'], array_values(array_unique(array_column($this->repo->search([], false, 50, 0)['rows'], 'kind'))));
        self::assertSame(0, $this->repo->search(['kind' => 'invoice'], false, 50, 0)['total'], 'asking for invoices by filter must not bypass the role');
        self::assertSame(['certificate' => 1, 'invoice' => 0, 'return' => 0], $this->repo->counts(false));
    }

    public function testFiltersByCciNumberNxpNumberClientTypeAndDate(): void
    {
        $this->certificate('Tiger Foods', 'XG2026001', '2026-00001', 'CCI', 'issued', '2026-06-10 09:00:00');
        $this->certificate('Ilera Ltd', 'XG2026002', '2026-00002', 'NNCI', 'issued', '2026-07-15 09:00:00');

        self::assertSame(1, $this->repo->search(['q' => '2026-00002'], true, 50, 0)['total'], 'by CCI number');
        self::assertSame(1, $this->repo->search(['q' => 'XG2026001'], true, 50, 0)['total'], 'by NXP number');
        self::assertSame(1, $this->repo->search(['q' => 'Ilera'], true, 50, 0)['total'], 'by client');
        self::assertSame(1, $this->repo->search(['type' => 'NNCI'], true, 50, 0)['total'], 'by type');
        self::assertSame(1, $this->repo->search(['from' => '2026-07-01', 'to' => '2026-07-31'], true, 50, 0)['total'], 'by date range');
        self::assertSame(1, $this->repo->search(['to' => '2026-06-10'], true, 50, 0)['total'], 'the "to" date is inclusive');
        self::assertSame(0, $this->repo->search(['q' => '%'], true, 50, 0)['total'], 'LIKE wildcards in the search are literal');
    }

    public function testASingleKindFilterWorksForEveryKind(): void
    {
        $this->certificate('Tiger Foods', 'NXP-A', '2026-00001');
        $this->invoice('ADW/2026/001');
        $this->statutoryReturn();

        foreach (ArchiveRepository::KINDS as $kind) {
            $r = $this->repo->search(['kind' => $kind], true, 50, 0);
            self::assertSame(1, $r['total'], $kind);
            self::assertSame($kind, $r['rows'][0]['kind']);
        }
    }

    public function testTheZipHoldsTheFilesFiledByKindAndMonthWithAnIndex(): void
    {
        $this->certificate('Tiger Foods', 'NXP-A', '2026-00001');
        $this->invoice('ADW/2026/001');
        $this->statutoryReturn();

        $zip = $this->service->zip([], true);
        $z = new ZipArchive();
        self::assertTrue($z->open($zip['path']));
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $names[] = $z->getNameIndex($i);
        }

        self::assertContains('certificates/2026/06/CCI 2026-00001.pdf', $names);
        self::assertContains('cbn-invoices/2026/07/ADW-2026-001.pdf', $names, 'slashes in invoice numbers are made filename-safe');
        self::assertContains('statutory-returns/2026/07/CBN 2026-06-01 to 2026-06-30.csv', $names);
        self::assertContains('index.csv', $names);
        self::assertSame(3, $zip['included']);
        $z->close();
        unlink($zip['path']);
    }

    public function testAFileThatFailsItsSignatureIsLeftOutAndNamedInTheIndex(): void
    {
        $uuid = $this->certificate('Tiger Foods', 'NXP-A', '2026-00001');
        file_put_contents($this->root . '/documents/' . $uuid . '.pdf', 'tampered', FILE_APPEND);

        $zip = $this->service->zip([], true);
        $z = new ZipArchive();
        $z->open($zip['path']);

        self::assertSame(0, $zip['included']);
        self::assertSame(1, $zip['skipped']);
        self::assertFalse($z->locateName('certificates/2026/06/CCI 2026-00001.pdf'));
        self::assertStringContainsString('failed integrity check', (string) $z->getFromName('index.csv'));
        $z->close();
        unlink($zip['path']);
    }

    public function testAnEmptyOrOversizedSelectionIsRefused(): void
    {
        $this->expectException(ApiException::class);
        $this->service->zip(['q' => 'nothing-matches-this'], true);
    }
}
