<?php

declare(strict_types=1);

namespace App\Compliance;

use DateTimeImmutable;
use DateTimeZone;

/** Shapes a `compliance_tracking` row for the API. Internal `id` is dropped. */
final class Representation
{
    /** @param array<string,mixed> $row */
    public static function tracking(array $row): array
    {
        return [
            'inspector_uuid'          => (string) $row['inspector_uuid'],
            'inspector_name'          => (string) $row['inspector_name'],
            'inspector_zone'          => $row['inspector_zone'] !== null ? (string) $row['inspector_zone'] : null,
            'period_month'            => self::date($row['period_month']),
            'missed_windows_count'    => (int) $row['missed_windows_count'],
            'consecutive_miss_count'  => (int) $row['consecutive_miss_count'],
            'alert_triggered'         => (bool) $row['alert_triggered'],
            'last_evaluated_at'       => self::ts($row['last_evaluated_at']),
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
