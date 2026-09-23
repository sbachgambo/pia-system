<?php

declare(strict_types=1);

namespace App\Income;

use App\Documents\Fees;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The agency's income: the PIA service fee (Fees::SERVICE_FEE_RATE, 0.35%) on
 * the FOB value of every issued CCI — the same rate and the same certificates
 * the monthly CBN invoice bills, so the report and the invoices always agree.
 *
 *  - income per CCI = FOB × that CCI's exchange rate × 0.35%, to the kobo;
 *  - a CCI with no exchange rate can't be put in naira: it is listed under
 *    `missing` and left out of the total, never guessed (the dashboard says so);
 *  - NNCIs (imports) and voided certificates earn nothing and are not counted.
 *
 * The invoices bill per region line (FOB ₦ of the line × rate), so an invoice
 * total can differ from the sum of its CCIs here by a few kobo of rounding.
 *
 * Periods are calendar periods in UTC, like the invoices.
 */
final class IncomeReport
{
    public const PERIODS = [
        'this_month'   => 'This month',
        'last_month'   => 'Last month',
        'this_quarter' => 'This quarter',
        'this_year'    => 'This year',
        'last_year'    => 'Last year',
        'custom'       => 'Custom dates',
    ];

    public function __construct(private readonly IncomeRepository $repo)
    {
    }

    /**
     * Resolve a period choice to [from, to) plus a label.
     *
     * @return array{key:string,from:DateTimeImmutable,to:DateTimeImmutable,label:string,from_date:string,to_date:string}
     */
    public static function period(string $key, ?string $from = null, ?string $to = null, ?DateTimeImmutable $now = null): array
    {
        $utc = new DateTimeZone('UTC');
        $now ??= new DateTimeImmutable('now', $utc);
        $month = $now->modify('first day of this month')->setTime(0, 0);

        switch ($key) {
            case 'last_month':
                $start = $month->modify('-1 month');
                $end = $month;
                break;
            case 'this_quarter':
                $q = intdiv((int) $now->format('n') - 1, 3);
                $start = new DateTimeImmutable($now->format('Y') . '-' . str_pad((string) ($q * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01', $utc);
                $end = $start->modify('+3 months');
                break;
            case 'this_year':
                $start = new DateTimeImmutable($now->format('Y') . '-01-01', $utc);
                $end = $start->modify('+1 year');
                break;
            case 'last_year':
                $start = new DateTimeImmutable(((int) $now->format('Y') - 1) . '-01-01', $utc);
                $end = $start->modify('+1 year');
                break;
            case 'custom':
                $start = self::date($from, 'from');
                $last = self::date($to, 'to');
                if ($last < $start) {
                    throw new ApiException(422, 'validation_failed', 'The end date is before the start date.', ['field' => 'to']);
                }
                $end = $last->modify('+1 day');
                break;
            default:
                $key = 'this_month';
                $start = $month;
                $end = $month->modify('+1 month');
        }

        $lastDay = $end->modify('-1 day');
        $label = match ($key) {
            'this_month', 'last_month' => $start->format('F Y'),
            'this_quarter' => 'Q' . (intdiv((int) $start->format('n') - 1, 3) + 1) . ' ' . $start->format('Y'),
            'this_year', 'last_year' => $start->format('Y'),
            default => $start->format('j M Y') . ' – ' . $lastDay->format('j M Y'),
        };

        return [
            'key'       => $key,
            'from'      => $start,
            'to'        => $end,
            'label'     => $label,
            'from_date' => $start->format('Y-m-d'),
            'to_date'   => $lastDay->format('Y-m-d'),
        ];
    }

    /**
     * Everything the report shows for one period.
     *
     * @param array{from:DateTimeImmutable,to:DateTimeImmutable} $period
     * @return array<string,mixed>
     */
    public function build(array $period): array
    {
        $rate = Fees::SERVICE_FEE_RATE;
        $rows = [];
        $missing = [];
        $byMonth = $byClient = $byRegion = [];
        $fobNgn = $income = 0.0;

        foreach ($this->repo->ccis($period['from'], $period['to']) as $c) {
            $fx = $c['exchange_rate'] !== null && $c['exchange_rate'] !== '' ? (float) $c['exchange_rate'] : null;
            $row = [
                'document_uuid'   => (string) $c['document_uuid'],
                'cci_number'      => (string) $c['document_number'],
                'issued_at'       => (string) $c['issued_at'],
                'nxp_uuid'        => (string) $c['nxp_uuid'],
                'nxp_number'      => $c['nxp_number'] !== null ? (string) $c['nxp_number'] : null,
                'client_name'     => (string) $c['client_name'],
                'region'          => trim((string) $c['zone']) !== '' ? trim((string) $c['zone']) : 'Unspecified',
                'currency'        => (string) $c['currency'],
                'fob'             => round((float) $c['declared_value'], 2),
                'exchange_rate'   => $fx,
                'fob_ngn'         => null,
                'income'          => null,
                'inspection_uuid' => (string) $c['inspection_uuid'],
            ];

            if ($fx === null || $fx <= 0.0) {
                $missing[] = $row;
                $rows[] = $row;
                continue;
            }

            $row['fob_ngn'] = round($row['fob'] * $fx, 2);
            $row['income'] = round($row['fob_ngn'] * $rate, 2);
            $rows[] = $row;

            $fobNgn += $row['fob_ngn'];
            $income += $row['income'];

            $ym = substr($row['issued_at'], 0, 7);
            self::add($byMonth, $ym, $ym, $row);
            self::add($byClient, mb_strtolower($row['client_name']), $row['client_name'], $row);
            self::add($byRegion, mb_strtolower($row['region']), $row['region'], $row);
        }

        ksort($byMonth);
        $byIncome = static fn (array $a, array $b): int => [$b['income'], $a['label']] <=> [$a['income'], $b['label']];
        uasort($byClient, $byIncome);
        uasort($byRegion, $byIncome);

        $invoices = $this->repo->invoices($period['from'], $period['to']);
        $invoiced = $paid = 0.0;
        foreach ($invoices as $inv) {
            $invoiced += (float) $inv['fee_ngn'];
            if ($inv['status'] === 'paid') {
                $paid += (float) $inv['fee_ngn'];
            }
        }

        return [
            'rate'        => $rate,
            'rate_label'  => Fees::percent($rate),
            'rows'        => $rows,
            'cci_count'   => count($rows) - count($missing),
            'fob_ngn'     => round($fobNgn, 2),
            'income'      => round($income, 2),
            'missing'     => $missing,
            'by_month'    => array_values(array_map(static function (array $m): array {
                $d = new DateTimeImmutable($m['label'] . '-01', new DateTimeZone('UTC'));

                return $m + ['name' => $d->format('F Y'), 'from' => $d->format('Y-m-d'), 'to' => $d->modify('last day of this month')->format('Y-m-d')];
            }, $byMonth)),
            'by_client'   => array_values($byClient),
            'by_region'   => array_values($byRegion),
            'invoices'    => $invoices,
            'invoiced'    => round($invoiced, 2),
            'paid'        => round($paid, 2),
            'outstanding' => round($invoiced - $paid, 2),
        ];
    }

    /**
     * Income per calendar month for the last $months months (this one
     * included), zero-filled, oldest first — the dashboard's bar chart.
     *
     * @return list<array{ym:string,label:string,name:string,from:string,to:string,income:float,count:int}>
     */
    public function monthly(int $months = 12, ?DateTimeImmutable $now = null): array
    {
        $months = max(1, min(36, $months));
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $first = $now->modify('first day of this month')->setTime(0, 0)->modify('-' . ($months - 1) . ' months');
        $built = $this->build(['from' => $first, 'to' => $now->modify('first day of next month')->setTime(0, 0)]);

        $found = [];
        foreach ($built['by_month'] as $m) {
            $found[$m['label']] = $m;
        }

        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $first->modify("+{$i} months");
            $ym = $m->format('Y-m');
            $out[] = [
                'ym'     => $ym,
                'label'  => $m->format('M'),
                'name'   => $m->format('F Y'),
                'from'   => $m->format('Y-m-d'),
                'to'     => $m->modify('last day of this month')->format('Y-m-d'),
                'income' => (float) ($found[$ym]['income'] ?? 0.0),
                'count'  => (int) ($found[$ym]['count'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The report as a spreadsheet: one line per CCI, then the total.
     *
     * @param array<string,mixed> $report from build()
     * @return array{headers:list<string>,rows:list<list<string>>}
     */
    public static function csvRows(array $report): array
    {
        $headers = ['CCI no.', 'Issued', 'NXP no.', 'Client', 'Region', 'Currency', 'FOB', 'Exchange rate', 'FOB (NGN)',
            'Income (NGN) @ ' . $report['rate_label'], 'Note'];
        $rows = [];
        foreach ($report['rows'] as $r) {
            $rows[] = [
                $r['cci_number'], substr($r['issued_at'], 0, 10), (string) $r['nxp_number'], $r['client_name'], $r['region'],
                $r['currency'], number_format($r['fob'], 2, '.', ''),
                $r['exchange_rate'] !== null ? (string) $r['exchange_rate'] : '',
                $r['fob_ngn'] !== null ? number_format($r['fob_ngn'], 2, '.', '') : '',
                $r['income'] !== null ? number_format($r['income'], 2, '.', '') : '',
                $r['income'] === null ? 'No exchange rate recorded - not counted' : '',
            ];
        }
        $rows[] = ['TOTAL', '', '', '', '', '', '', '', number_format($report['fob_ngn'], 2, '.', ''), number_format($report['income'], 2, '.', ''),
            $report['cci_count'] . ' CCIs counted'];

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param array<string,array{label:string,count:int,fob_ngn:float,income:float}> $bucket
     * @param array<string,mixed> $row
     */
    private static function add(array &$bucket, string $key, string $label, array $row): void
    {
        $bucket[$key] ??= ['label' => $label, 'count' => 0, 'fob_ngn' => 0.0, 'income' => 0.0];
        $bucket[$key]['count']++;
        $bucket[$key]['fob_ngn'] = round($bucket[$key]['fob_ngn'] + $row['fob_ngn'], 2);
        $bucket[$key]['income'] = round($bucket[$key]['income'] + $row['income'], 2);
    }

    private static function date(?string $v, string $field): DateTimeImmutable
    {
        $v = trim((string) $v);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new ApiException(422, 'validation_failed', $field === 'from' ? 'Choose a start date.' : 'Choose an end date.', ['field' => $field]);
        }

        return $d;
    }
}
