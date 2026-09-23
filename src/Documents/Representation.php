<?php

declare(strict_types=1);

namespace App\Documents;

use DateTimeImmutable;
use DateTimeZone;

/** Shapes a `documents` row for the API. Internal `id` is dropped. */
final class Representation
{
    /** @param array<string,mixed> $row */
    public static function document(array $row): array
    {
        return [
            'uuid'             => (string) $row['uuid'],
            'inspection_uuid'  => (string) $row['inspection_uuid'],
            'type'             => (string) $row['type'],
            'document_number'  => (string) $row['document_number'],
            'content_hash'     => (string) $row['content_hash'],
            'hmac_signature'   => (string) $row['hmac_signature'],
            'status'           => (string) $row['status'],
            'issued_at'        => self::ts($row['issued_at']),
            'issued_by_uuid'   => $row['issued_by_uuid'] !== null ? (string) $row['issued_by_uuid'] : null,
            'issued_by_name'   => $row['issued_by_name'] !== null ? (string) $row['issued_by_name'] : null,
        ];
    }

    private static function ts(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
