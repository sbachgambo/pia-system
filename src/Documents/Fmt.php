<?php

declare(strict_types=1);

namespace App\Documents;

/** Small formatting helpers for the document templates — keeps the .php templates plain. */
final class Fmt
{
    public static function money(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $decimals);
    }

    public static function date(mixed $value, string $format = 'd-M-Y'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable((string) $value))->format($format);
        } catch (\Exception) {
            return '';
        }
    }

    public static function orDash(mixed $value): string
    {
        $s = trim((string) ($value ?? ''));

        return $s === '' ? '—' : $s;
    }
}
