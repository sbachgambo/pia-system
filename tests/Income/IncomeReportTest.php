<?php

declare(strict_types=1);

namespace App\Tests\Income;

use App\Income\IncomeReport;
use App\Income\IncomeRepository;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The income report: 0.35% of FOB (in naira, at each CCI's own rate) on every
 * issued CCI — and nothing else.
 */
final class IncomeReportTest extends DatabaseTestCase
{
    private IncomeReport $report;
    private int $staffId;
    private int $inspectorId;
    private int $n = 0;

    protected function dirtyTables(): array
    {
        return ['cbn_invoices', 'inspection_shipment_details', 'documents', 'inspections', 'inspection_requests',
            'consignments', 'clients', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = new IncomeReport(new IncomeRepository($this->pdo));
        $this->staffId = $this->makeUser(['role' => 'office_reviewer'])['id'];
        $this->inspectorId = $this->makeUser(['role' => 'inspector'])['id'];
    }

    private function cci(string $issuedAt, float $fob, ?float $rate, string $client = 'Tiger Foods', string $zone = 'Lagos', string $type = 'CCI', string $status = 'issued'): void
    {
        $this->n++;
        $clientId = $this->makeClientRow(['name' => $client . ' ' . $this->n])['id'];
        $cons = $this->makeConsignmentRow($clientId, ['declared_value' => $fob, 'zone' => $zone, 'form_nxp_number' => 'INC-' . $this->n]);
        $req = $this->makeInspectionRequestRow($cons['id'], $this->staffId, ['status' => 'completed']);
        $insp = $this->makeScheduledInspectionRow($req['id'], $this->inspectorId, ['status' => 'finalized']);
        if ($rate !== null) {
            $this->pdo->prepare('INSERT INTO inspection_shipment_details (inspection_id, exchange_rate, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$insp['id'], $rate]);
        }
        $this->makeDocumentRow($insp['id'], ['issued_at' => $issuedAt, 'type' => $type, 'status' => $status, 'document_number' => 'INC-DOC-' . $this->n]);
    }

    private function september(): array
    {
        return IncomeReport::period('custom', '2026-09-01', '2026-09-30');
    }

    public function testIncomeIsPointThreeFivePercentOfFobInNaira(): void
    {
        $this->cci('2026-09-03 10:00:00', 10708.00, 1550.25);
        $this->cci('2026-09-20 10:00:00', 20000.00, 1600.00);

        $r = $this->report->build($this->september());

        // 10,708 × 1,550.25 = 16,600,077.00 → × 0.35% = 58,100.27
        // 20,000 × 1,600    = 32,000,000.00 → × 0.35% = 112,000.00
        self::assertSame(2, $r['cci_count']);
        self::assertSame(48600077.0, $r['fob_ngn']);
        self::assertSame(170100.27, $r['income']);
        self::assertSame('0.35%', $r['rate_label']);
    }

    public function testOnlyIssuedCcisInThePeriodEarnIncome(): void
    {
        $this->cci('2026-09-10 10:00:00', 1000.00, 1500.00);                              // counted
        $this->cci('2026-09-10 10:00:00', 1000.00, 1500.00, type: 'NNCI');                // import cert: no fee
        $this->cci('2026-09-10 10:00:00', 1000.00, 1500.00, status: 'void');              // voided: no fee
        $this->cci('2026-08-31 23:59:59', 1000.00, 1500.00);                              // last month
        $this->cci('2026-10-01 00:00:00', 1000.00, 1500.00);                              // next month

        $r = $this->report->build($this->september());

        self::assertSame(1, $r['cci_count']);
        self::assertSame(5250.0, $r['income']);
    }

    public function testACciWithoutAnExchangeRateIsListedNotGuessed(): void
    {
        $this->cci('2026-09-10 10:00:00', 1000.00, 1500.00);
        $this->cci('2026-09-11 10:00:00', 9999.00, null);

        $r = $this->report->build($this->september());

        self::assertSame(1, $r['cci_count']);
        self::assertCount(1, $r['missing']);
        self::assertSame('INC-DOC-2', $r['missing'][0]['cci_number']);
        self::assertSame(5250.0, $r['income'], 'the unconverted CCI is left out of the total');
        self::assertCount(2, $r['rows'], 'but it still appears in the list, flagged');
    }

    public function testBreakdownsByMonthClientAndRegionAddUpToTheTotal(): void
    {
        $this->cci('2026-07-05 10:00:00', 1000.00, 1500.00, 'Alpha', 'Lagos');
        $this->cci('2026-08-05 10:00:00', 2000.00, 1500.00, 'Beta', 'Kano');
        $this->cci('2026-08-06 10:00:00', 3000.00, 1500.00, 'Gamma', 'Kano');

        $r = $this->report->build(IncomeReport::period('custom', '2026-07-01', '2026-08-31'));

        self::assertSame(['2026-07', '2026-08'], array_column($r['by_month'], 'label'));
        self::assertSame('Kano', $r['by_region'][0]['label'], 'largest earner first');
        foreach (['by_month', 'by_client', 'by_region'] as $b) {
            self::assertEqualsWithDelta($r['income'], array_sum(array_column($r[$b], 'income')), 0.001, $b);
        }
    }

    public function testInvoicedAndPaidComeFromTheCbnInvoices(): void
    {
        $ins = $this->pdo->prepare(
            "INSERT INTO cbn_invoices (uuid, invoice_number, period_month, status, cci_count, fob_ngn, fee_rate, fee_ngn, `lines`, file_path, content_hash,
                 hmac_signature, issued_at, paid_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, 0, 0.0035, ?, '[]', 'invoices/x.pdf', REPEAT('0', 64), REPEAT('0', 64), UTC_TIMESTAMP(), ?,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $ins->execute(['00000000-0000-4000-8000-000000000001', 'ADW/2026/001', '2026-09-01', 'paid', 1000.00, '2026-09-30']);
        $ins->execute(['00000000-0000-4000-8000-000000000002', 'ADW/2026/002', '2026-09-01', 'void', 5000.00, null]);
        $ins->execute(['00000000-0000-4000-8000-000000000003', 'ADW/2026/003', '2026-09-01', 'issued', 250.00, null]);
        $ins->execute(['00000000-0000-4000-8000-000000000004', 'ADW/2026/004', '2026-10-01', 'issued', 999.00, null]);

        $r = $this->report->build($this->september());

        self::assertSame(1250.0, $r['invoiced'], 'void invoices and other months are left out');
        self::assertSame(1000.0, $r['paid']);
        self::assertSame(250.0, $r['outstanding']);
    }

    public function testPeriodsResolveToCalendarRanges(): void
    {
        $now = new DateTimeImmutable('2026-09-22 15:00:00', new DateTimeZone('UTC'));

        $cases = [
            'this_month'   => ['2026-09-01', '2026-09-30', 'September 2026'],
            'last_month'   => ['2026-08-01', '2026-08-31', 'August 2026'],
            'this_quarter' => ['2026-07-01', '2026-09-30', 'Q3 2026'],
            'this_year'    => ['2026-01-01', '2026-12-31', '2026'],
            'last_year'    => ['2025-01-01', '2025-12-31', '2025'],
            'nonsense'     => ['2026-09-01', '2026-09-30', 'September 2026'],
        ];
        foreach ($cases as $key => [$from, $to, $label]) {
            $p = IncomeReport::period($key, null, null, $now);
            self::assertSame([$from, $to, $label], [$p['from_date'], $p['to_date'], $p['label']], $key);
        }

        $this->expectException(ApiException::class);
        IncomeReport::period('custom', '2026-09-30', '2026-09-01');
    }

    public function testTheMonthlySeriesIsZeroFilled(): void
    {
        $this->cci('2026-09-03 10:00:00', 1000.00, 1500.00);

        $series = $this->report->monthly(12, new DateTimeImmutable('2026-09-22', new DateTimeZone('UTC')));

        self::assertCount(12, $series);
        self::assertSame('2025-10', $series[0]['ym']);
        self::assertSame('2026-09', $series[11]['ym']);
        self::assertSame(5250.0, $series[11]['income']);
        self::assertSame(0.0, $series[10]['income']);
    }
}
