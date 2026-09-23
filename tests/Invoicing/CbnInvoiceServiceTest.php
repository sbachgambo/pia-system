<?php

declare(strict_types=1);

namespace App\Tests\Invoicing;

use App\Audit\AuditLog;
use App\Documents\DocumentSigner;
use App\Documents\PdfRenderer;
use App\Http\View\Renderer;
use App\Invoicing\CbnInvoiceRepository;
use App\Invoicing\CbnInvoiceService;
use App\Invoicing\InvoiceSettingsRepository;
use App\Invoicing\InvoiceStorage;
use App\Settings\CompanySettingsRepository;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;

/**
 * The monthly CBN invoice: what blocks it, what it bills, how it's numbered,
 * and the only two changes an issued invoice may undergo.
 */
final class CbnInvoiceServiceTest extends DatabaseTestCase
{
    private const HMAC_KEY = 'test-hmac-key-for-invoices-0123456789abcdef';

    private CbnInvoiceService $service;
    private InvoiceSettingsRepository $settings;
    private string $storageDir;
    private int $adminId;
    private int $staffId;
    private int $inspectorId;
    private int $clientId;

    protected function dirtyTables(): array
    {
        return ['audit_log', 'cbn_invoices', 'invoice_sequences', 'invoice_settings', 'inspection_shipment_details',
            'documents', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $settings = $this->settings();
        $tmp = sys_get_temp_dir() . '/pia_invoice_test_' . getmypid();
        $this->storageDir = $tmp . '/invoices';

        $this->settings = new InvoiceSettingsRepository($this->pdo);
        $this->service = new CbnInvoiceService(
            $this->pdo,
            new CbnInvoiceRepository($this->pdo),
            $this->settings,
            new CompanySettingsRepository($this->pdo, ['name' => 'ADWOL Test Ltd', 'address' => '1 Test Road', 'representative_title' => 'MD']),
            new InvoiceStorage($this->storageDir),
            new DocumentSigner(self::HMAC_KEY),
            new Renderer($settings['app']['base_path'] . '/templates', ['app_name' => 'ADWOL PIA']),
            new PdfRenderer($tmp . '/mpdf'),
            new AuditLog($this->pdo),
        );

        $this->adminId = $this->makeUser(['role' => 'admin'])['id'];
        $this->staffId = $this->makeUser(['role' => 'office_reviewer'])['id'];
        $this->inspectorId = $this->makeUser(['role' => 'inspector'])['id'];
        $this->clientId = $this->makeClientRow(['name' => 'Tiger Foods'])['id'];
    }

    private function withBank(): void
    {
        $this->settings->update(array_merge(InvoiceSettingsRepository::DEFAULTS, [
            'bank_account_name' => 'ADWOL Test Ltd', 'bank_account_number' => '0123456789', 'bank_name' => 'Test Bank',
        ]), $this->adminId);
    }

    /** One issued certificate with its FOB, zone and (optional) exchange rate. */
    private function cci(string $issuedAt, float $fob, ?float $rate, string $zone = 'South East', string $type = 'CCI', string $status = 'issued'): void
    {
        static $n = 0;
        $n++;
        $cons = $this->makeConsignmentRow($this->clientId, ['declared_value' => $fob, 'zone' => $zone, 'form_nxp_number' => 'INV-NXP-' . $n . '-' . getmypid()]);
        $req = $this->makeInspectionRequestRow($cons['id'], $this->staffId);
        $insp = $this->makeScheduledInspectionRow($req['id'], $this->inspectorId, ['status' => 'finalized']);
        $this->makeDocumentRow($insp['id'], ['type' => $type, 'status' => $status, 'issued_at' => $issuedAt, 'document_number' => '2026-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT) . substr((string) getmypid(), -2)]);

        if ($rate !== null) {
            $this->pdo->prepare('INSERT INTO inspection_shipment_details (inspection_id, exchange_rate, created_at, updated_at) VALUES (:i, :r, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute(['i' => $insp['id'], 'r' => $rate]);
        }
    }

    private function month(string $m): \DateTimeImmutable
    {
        return CbnInvoiceService::parseMonth($m);
    }

    public function testPreviewBillsOnlyThatMonthsIssuedCcis(): void
    {
        $this->withBank();
        $this->cci('2026-06-01 00:00:00', 1000, 1500);        // first second of June — in
        $this->cci('2026-06-30 23:59:59', 2000, 1500);        // last second of June — in
        $this->cci('2026-07-01 00:00:00', 9999, 1500);        // July — out
        $this->cci('2026-06-15 10:00:00', 5000, 1500, type: 'NNCI');   // NNCI (NESS unpaid) — not billed
        $this->cci('2026-06-15 10:00:00', 7000, 1500, status: 'void'); // voided certificate — not billed

        $p = $this->service->preview($this->month('2026-06'));

        self::assertSame([], $p['blockers']);
        self::assertSame(2, $p['figures']['cci_count']);
        self::assertSame(4500000.0, $p['figures']['fob_ngn']);
        self::assertSame(15750.0, $p['figures']['fee_ngn']);
        self::assertSame('JUNE 2026', $p['label']);
    }

    public function testEachBlockerIsReported(): void
    {
        self::assertStringContainsString('No CCIs were issued', implode(' ', $this->service->preview($this->month('2026-06'))['blockers']));

        $this->cci('2026-06-10 09:00:00', 1000, null);
        $blockers = implode(' ', $this->service->preview($this->month('2026-06'))['blockers']);
        self::assertStringContainsString('no exchange rate', $blockers);
        self::assertStringContainsString('bank account', $blockers);
    }

    public function testIssueFreezesTheFiguresSignsThePdfAndAudits(): void
    {
        $this->withBank();
        $this->cci('2026-06-10 09:00:00', 1000, 1500);

        $row = $this->service->issue($this->month('2026-06'), $this->adminId, '203.0.113.9');

        self::assertSame('ADW/' . gmdate('Y') . '/001', $row['invoice_number']);
        self::assertSame('issued', $row['status']);
        self::assertSame('5250.00', $row['fee_ngn']);
        self::assertSame('2026-06-01', $row['period_month']);
        self::assertCount(1, json_decode((string) $row['lines'], true));

        $file = $this->service->download((string) $row['uuid']);
        self::assertStringStartsWith('%PDF', $file['bytes']);
        self::assertSame('CBN-invoice-ADW-' . gmdate('Y') . '-001.pdf', $file['filename']);

        $audit = $this->pdo->query("SELECT actor_id FROM audit_log WHERE action = 'cbn_invoice.issue'")->fetchColumn();
        self::assertSame($this->adminId, (int) $audit);
    }

    public function testNumbersStartAtOneEachYearAndRunWithoutGaps(): void
    {
        $this->withBank();
        $this->cci('2026-05-10 09:00:00', 1000, 1500);
        $this->cci('2026-06-10 09:00:00', 1000, 1500);

        // A refused attempt (March has no CCIs) must not consume a number.
        try {
            $this->service->issue($this->month('2026-03'), $this->adminId, null);
            self::fail('a month with no CCIs cannot be invoiced');
        } catch (ApiException) {
        }

        $a = $this->service->issue($this->month('2026-05'), $this->adminId, null);
        $b = $this->service->issue($this->month('2026-06'), $this->adminId, null);

        self::assertStringEndsWith('/001', $a['invoice_number'], 'the first invoice of a year is 001 (no surrogate-id trap)');
        self::assertStringEndsWith('/002', $b['invoice_number']);
    }

    public function testAFailureAfterTheNumberIsAllocatedDoesNotBurnTheNumber(): void
    {
        $this->withBank();
        $this->cci('2026-06-10 09:00:00', 1000, 1500);
        $tmp = sys_get_temp_dir() . '/pia_invoice_test_' . getmypid();

        // Same service, but the PDF template can't be found — so issuing fails
        // AFTER the invoice number has been allocated.
        $broken = new CbnInvoiceService(
            $this->pdo,
            new CbnInvoiceRepository($this->pdo),
            $this->settings,
            new CompanySettingsRepository($this->pdo, ['name' => 'X', 'address' => 'Y', 'representative_title' => 'Z']),
            new InvoiceStorage($this->storageDir),
            new DocumentSigner(self::HMAC_KEY),
            new Renderer($tmp . '/no-templates-here', []),
            new PdfRenderer($tmp . '/mpdf'),
            new AuditLog($this->pdo),
        );
        try {
            $broken->issue($this->month('2026-06'), $this->adminId, null);
            self::fail('issuing without a template should fail');
        } catch (\Throwable) {
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cbn_invoices')->fetchColumn());
        $row = $this->service->issue($this->month('2026-06'), $this->adminId, null);
        self::assertStringEndsWith('/001', $row['invoice_number'], 'the failed attempt must not have used up 001');
    }

    public function testAMonthCanOnlyHaveOneLiveInvoiceUntilItIsVoided(): void
    {
        $this->withBank();
        $this->cci('2026-06-10 09:00:00', 1000, 1500);
        $first = $this->service->issue($this->month('2026-06'), $this->adminId, null);

        try {
            $this->service->issue($this->month('2026-06'), $this->adminId, null);
            self::fail('a second invoice for the same month should be refused');
        } catch (ApiException $e) {
            self::assertStringContainsString('already covers this month', $e->getMessage());
        }

        $this->service->void((string) $first['uuid'], 'Wrong exchange rate', $this->adminId, null);
        $replacement = $this->service->issue($this->month('2026-06'), $this->adminId, null);

        self::assertNotSame($first['invoice_number'], $replacement['invoice_number']);
    }

    public function testPaymentRulesAndAPaidInvoiceIsFinal(): void
    {
        $this->withBank();
        $this->cci('2026-06-10 09:00:00', 1000, 1500);
        $inv = $this->service->issue($this->month('2026-06'), $this->adminId, null);
        $uuid = (string) $inv['uuid'];

        foreach (['not-a-date', '2099-01-01', '2000-01-01'] as $bad) {
            try {
                $this->service->markPaid($uuid, $bad, null, $this->adminId, null);
                self::fail("payment date {$bad} should be refused");
            } catch (ApiException $e) {
                self::assertSame('paid_at', $e->getDetails()['field']);
            }
        }

        $this->service->markPaid($uuid, gmdate('Y-m-d'), 'CBN-TRF-1', $this->adminId, null);
        $paid = $this->service->get($uuid);
        self::assertSame('paid', $paid['status']);
        self::assertSame('CBN-TRF-1', $paid['payment_reference']);

        $this->expectException(ApiException::class);
        $this->service->void($uuid, 'changed my mind', $this->adminId, null);
    }

    public function testVoidNeedsAReason(): void
    {
        $this->withBank();
        $this->cci('2026-06-10 09:00:00', 1000, 1500);
        $inv = $this->service->issue($this->month('2026-06'), $this->adminId, null);

        $this->expectException(ApiException::class);
        $this->service->void((string) $inv['uuid'], '   ', $this->adminId, null);
    }

    public function testATamperedInvoiceFileIsNotServed(): void
    {
        $this->withBank();
        $this->cci('2026-06-10 09:00:00', 1000, 1500);
        $inv = $this->service->issue($this->month('2026-06'), $this->adminId, null);

        file_put_contents($this->storageDir . '/' . basename((string) $inv['file_path']), 'X', FILE_APPEND);

        try {
            $this->service->download((string) $inv['uuid']);
            self::fail('a tampered invoice must not be served');
        } catch (ApiException $e) {
            self::assertSame('integrity_failed', $e->getErrorCode());
        }
    }

    public function testMonthParsingIsStrict(): void
    {
        self::assertSame('2026-06-01', CbnInvoiceService::parseMonth('2026-06')->format('Y-m-d'));

        $this->expectException(ApiException::class);
        CbnInvoiceService::parseMonth('2026-13');
    }
}
