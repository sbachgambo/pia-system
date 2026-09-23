<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * The two percentage charges on an export's FOB value, defined once so the
 * CCI, the CBN statutory return and the CBN invoice can never disagree.
 *
 *  - NESS fee (Nigerian Export Supervision Scheme): 0.5% of FOB, paid by the
 *    exporter. Printed on the CCI (box 50) and totalled in the CBN return.
 *    Confirmed by the client, Sep 2026.
 *  - PIA service fee: 0.35% of FOB, billed by the inspection agency to the CBN
 *    each month (sample-docs "PIA Invoice June 2023").
 *
 * Amounts are in whatever currency the FOB value is in; pass an exchange rate
 * to get naira.
 */
final class Fees
{
    public const NESS_RATE = 0.005;
    public const SERVICE_FEE_RATE = 0.0035;

    /** e.g. "0.5%" — for labels, so the printed rate always matches the one used. */
    public static function percent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 3, '.', ''), '0'), '.') . '%';
    }

    public static function ness(float $fob, ?float $exchangeRate = null): float
    {
        return round($fob * ($exchangeRate ?? 1.0) * self::NESS_RATE, 2);
    }

    public static function serviceFee(float $fob, ?float $exchangeRate = null): float
    {
        return round($fob * ($exchangeRate ?? 1.0) * self::SERVICE_FEE_RATE, 2);
    }
}
