<?php

declare(strict_types=1);

namespace App\Invoicing;

use App\Documents\Fees;

/**
 * Turns the month's issued CCIs into the invoice table — pure arithmetic, no
 * database, so it can be checked against the client's own sample invoice.
 *
 * One line per region (the record's zone) and FOB currency, like the sample:
 *   REGION | NO. OF CCIs | CURRENCY | F.O.B VALUE | EXCHANGE RATE | F.O.B VALUE (₦) | FEES PAYABLE (₦)
 *
 *  - FOB (₦) is summed per CCI (its own FOB × its own exchange rate), so a
 *    month with several rates is exact;
 *  - the exchange rate shown is the resulting weighted average (FOB ₦ ÷ FOB);
 *  - each line's fee is its FOB (₦) × the service-fee rate, and the total is
 *    the sum of the lines as printed, so the invoice always adds up.
 *
 * A CCI without an exchange rate can't be converted to naira; it is reported
 * in `missing_rate` rather than silently dropped or guessed.
 */
final class InvoiceCalculator
{
    /**
     * @param list<array{document_number:string,zone:string,currency:string,declared_value:mixed,exchange_rate:mixed}> $ccis
     * @return array{
     *   lines: list<array{region:string,currency:string,cci_count:int,fob:float,exchange_rate:float,fob_ngn:float,fee_ngn:float}>,
     *   cci_count:int, fob_ngn:float, fee_ngn:float, fee_rate:float,
     *   missing_rate: list<string>
     * }
     */
    public static function calculate(array $ccis, float $feeRate = Fees::SERVICE_FEE_RATE): array
    {
        $groups = [];
        $missing = [];

        foreach ($ccis as $c) {
            $rate = $c['exchange_rate'] !== null && $c['exchange_rate'] !== '' ? (float) $c['exchange_rate'] : null;
            if ($rate === null || $rate <= 0.0) {
                $missing[] = (string) $c['document_number'];
                continue;
            }

            $region = trim((string) $c['zone']) !== '' ? trim((string) $c['zone']) : 'Unspecified';
            $key = mb_strtolower($region) . '|' . $c['currency'];
            $groups[$key] ??= ['region' => $region, 'currency' => (string) $c['currency'], 'cci_count' => 0, 'fob' => 0.0, 'fob_ngn' => 0.0];

            $fob = (float) $c['declared_value'];
            $groups[$key]['cci_count']++;
            $groups[$key]['fob'] += $fob;
            $groups[$key]['fob_ngn'] += $fob * $rate;
        }

        ksort($groups);

        $lines = [];
        $count = 0;
        $fobNgn = 0.0;
        $fee = 0.0;
        foreach ($groups as $g) {
            $lineNgn = round($g['fob_ngn'], 2);
            $lineFee = round($lineNgn * $feeRate, 2);
            $lines[] = [
                'region'        => $g['region'],
                'currency'      => $g['currency'],
                'cci_count'     => $g['cci_count'],
                'fob'           => round($g['fob'], 2),
                'exchange_rate' => $g['fob'] > 0.0 ? round($g['fob_ngn'] / $g['fob'], 4) : 0.0,
                'fob_ngn'       => $lineNgn,
                'fee_ngn'       => $lineFee,
            ];
            $count += $g['cci_count'];
            $fobNgn += $lineNgn;
            $fee += $lineFee;
        }

        return [
            'lines'        => $lines,
            'cci_count'    => $count,
            'fob_ngn'      => round($fobNgn, 2),
            'fee_ngn'      => round($fee, 2),
            'fee_rate'     => $feeRate,
            'missing_rate' => $missing,
        ];
    }

    /** "Seventeen Million … Naira and Forty-One Kobo Only." — the sample's wording. */
    public static function nairaInWords(float $amount): string
    {
        $amount = round($amount, 2);
        $naira = (int) floor($amount);
        $kobo = (int) round(($amount - $naira) * 100);

        $words = \App\Documents\NumberToWords::integer($naira) . ' Naira';
        if ($kobo > 0) {
            $words .= ' and ' . \App\Documents\NumberToWords::integer($kobo) . ' Kobo';
        }

        // The sample invoice writes "Six Hundred"; NumberToWords keeps the sample
        // CCI's lower-case "hundred", so title-case it here only.
        return str_replace(' hundred', ' Hundred', $words) . ' Only.';
    }
}
