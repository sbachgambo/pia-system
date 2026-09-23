<?php

declare(strict_types=1);

namespace App\Returns;

use DateTimeImmutable;
use DateTimeZone;

/** Shapes a `statutory_returns` row for the API. Internal `id` is dropped. */
final class Representation
{
    /** @param array<string,mixed> $row */
    public static function statutoryReturn(array $row): array
    {
        return [
            'uuid'                => (string) $row['uuid'],
            'agency'              => (string) $row['agency'],
            'period_start'        => self::date($row['period_start']),
            'period_end'          => self::date($row['period_end']),
            'format'              => (string) $row['format'],
            'row_count'           => (int) $row['row_count'],
            'status'              => (string) $row['status'],
            'generated_at'        => self::ts($row['generated_at']),
            'generated_by_uuid'   => $row['generated_by_uuid'] !== null ? (string) $row['generated_by_uuid'] : null,
            'generated_by_name'   => $row['generated_by_name'] !== null ? (string) $row['generated_by_name'] : null,
            'submitted_at'        => self::ts($row['submitted_at'] ?? null),
            'submitted_by_uuid'   => $row['submitted_by_uuid'] !== null ? (string) $row['submitted_by_uuid'] : null,
            'submitted_by_name'   => $row['submitted_by_name'] !== null ? (string) $row['submitted_by_name'] : null,
        ];
    }

    private static function date(mixed $v): string
    {
        return (new DateTimeImmutable((string) $v))->format('Y-m-d');
    }

    private static function ts(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
