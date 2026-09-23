<?php

declare(strict_types=1);

namespace App\Inspections;

use DateTimeImmutable;

/**
 * Shapes inspection rows for the API. Internal `id` is dropped; only uuids are
 * exposed. Timestamps are ISO-8601 UTC.
 */
final class Representation
{
    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $findings
     * @param list<array<string,mixed>> $attachments
     * @return array<string,mixed>
     */
    public static function inspection(array $row, array $findings = [], array $attachments = []): array
    {
        return [
            'uuid'                    => (string) $row['uuid'],
            'inspection_request_uuid' => (string) $row['inspection_request_uuid'],
            'inspector_uuid'          => (string) $row['inspector_uuid'],
            'consignment_uuid'        => (string) $row['consignment_uuid'],
            'client_name'             => (string) ($row['client_name'] ?? ''),
            'product_category'        => (string) ($row['product_category'] ?? ''),
            'nxp_number'              => isset($row['nxp_number']) ? (string) $row['nxp_number'] : null,
            'cci_number'              => isset($row['cci_number']) ? (string) $row['cci_number'] : null,
            'inspector_name'          => (string) ($row['inspector_name'] ?? ''),
            'scheduled_at'            => self::ts($row['scheduled_at']),
            'location_type'           => (string) $row['location_type'],
            'location_detail'         => (string) $row['location_detail'],
            'status'                  => (string) $row['status'],
            'started_at'              => self::ts($row['started_at'] ?? null),
            'synced_at'               => self::ts($row['synced_at'] ?? null),
            'finalized_at'            => self::ts($row['finalized_at'] ?? null),
            'findings'                => array_map([self::class, 'finding'], $findings),
            'attachments'             => array_map([self::class, 'attachment'], $attachments),
        ];
    }

    /** @param array<string,mixed> $f */
    public static function finding(array $f): array
    {
        return [
            'checklist_item' => (string) $f['checklist_item'],
            'expected_value' => $f['expected_value'] !== null ? (string) $f['expected_value'] : null,
            'observed_value' => $f['observed_value'] !== null ? (string) $f['observed_value'] : null,
            'result'         => (string) $f['result'],
            'notes'          => $f['notes'] !== null ? (string) $f['notes'] : null,
        ];
    }

    /** @param array<string,mixed> $a */
    public static function attachment(array $a): array
    {
        return [
            'client_uuid'     => (string) $a['client_uuid'],
            'file_type'       => (string) $a['file_type'],
            'original_name'   => $a['original_name'] !== null ? (string) $a['original_name'] : null,
            'mime_type'       => $a['mime_type'] !== null ? (string) $a['mime_type'] : null,
            'byte_size'       => $a['byte_size'] !== null ? (int) $a['byte_size'] : null,
            'captured_at'     => self::ts($a['captured_at']),
            'uploaded_at'     => self::ts($a['uploaded_at'] ?? null),
            'checksum_sha256' => (string) $a['checksum_sha256'],
        ];
    }

    private static function ts(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
