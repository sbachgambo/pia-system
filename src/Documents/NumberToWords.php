<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * Spells out a currency amount the way the sample CCI does — e.g.
 * "Ten Thousand Seven hundred and Eight dollars only" for 10708.00, or
 * "...dollars and Fifty Cents" when there's a fractional part. English only;
 * good up to 999,999,999,999.99.
 */
final class NumberToWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];
    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];
    private const SCALES = ['', ' Thousand', ' Million', ' Billion'];

    public static function amount(string|float $value, string $unit = 'dollars'): string
    {
        $value = round((float) $value, 2);
        $whole = (int) floor($value);
        $cents = (int) round(($value - $whole) * 100);

        $words = self::integer($whole) . ' ' . $unit;

        return $cents > 0
            ? $words . ' and ' . self::integer($cents) . ' Cents'
            : $words . ' only';
    }

    public static function integer(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }

        $negative = $n < 0;
        $n = abs($n);
        $groups = [];

        while ($n > 0) {
            $groups[] = $n % 1000;
            $n = intdiv($n, 1000);
        }

        $parts = [];
        foreach (array_reverse($groups, true) as $i => $group) {
            if ($group === 0) {
                continue;
            }
            $parts[] = self::underThousand($group) . self::SCALES[$i];
        }

        $out = implode(' ', $parts);

        return $negative ? 'Negative ' . $out : $out;
    }

    private static function underThousand(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        if ($n < 100) {
            $tens = self::TENS[intdiv($n, 10)];
            $rest = $n % 10;

            return $rest === 0 ? $tens : $tens . '-' . self::ONES[$rest];
        }

        $hundreds = self::ONES[intdiv($n, 100)] . ' hundred';
        $rest = $n % 100;

        return $rest === 0 ? $hundreds : $hundreds . ' and ' . self::underThousand($rest);
    }
}
