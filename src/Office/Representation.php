<?php

declare(strict_types=1);

namespace App\Office;

use DateTimeImmutable;

/**
 * Turns a raw DB row into the JSON shape the API returns:
 *  - the internal `id` is dropped (only `uuid` is exposed, per §3/§4),
 *  - timestamps become ISO-8601 UTC,
 *  - DECIMAL values stay strings (PDO already returns them as such) so money /
 *    quantity precision is never lost to a float.
 */
final class Representation
{
    /** @param array<string,mixed> $row */
    public static function client(array $row): array
    {
        return [
            'uuid'          => (string) $row['uuid'],
            'name'          => (string) $row['name'],
            'type'          => (string) $row['type'],
            'rc_number'     => $row['rc_number'] !== null ? (string) $row['rc_number'] : null,
            'address'       => (string) $row['address'],
            'contact_name'  => (string) $row['contact_name'],
            'contact_phone' => (string) $row['contact_phone'],
            'contact_email' => (string) $row['contact_email'],
            'created_at'    => self::ts($row['created_at']),
            'updated_at'    => self::ts($row['updated_at']),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function consignment(array $row): array
    {
        return [
            'uuid'                => (string) $row['uuid'],
            'client_uuid'         => (string) $row['client_uuid'],
            'direction'           => (string) $row['direction'],
            'product_category'    => (string) $row['product_category'],
            'product_description' => (string) $row['product_description'],
            'hs_code'             => $row['hs_code'] !== null ? (string) $row['hs_code'] : null,
            'quantity'            => (string) $row['quantity'],
            'unit_of_measure'     => (string) $row['unit_of_measure'],
            'declared_value'      => (string) $row['declared_value'],
            'currency'            => (string) $row['currency'],
            'origin_country'      => (string) $row['origin_country'],
            'destination_country' => (string) $row['destination_country'],
            'zone'                => (string) $row['zone'],
            'form_nxp_number'     => $row['form_nxp_number'] !== null ? (string) $row['form_nxp_number'] : null,
            'client_name'         => isset($row['client_name']) ? (string) $row['client_name'] : null,
            'created_at'          => self::ts($row['created_at']),
            'updated_at'          => self::ts($row['updated_at']),
        ];
    }

    /** @param array<string,mixed> $row */
    public static function inspectionRequest(array $row): array
    {
        return [
            'uuid'              => (string) $row['uuid'],
            'consignment_uuid' => (string) $row['consignment_uuid'],
            'client_name'      => (string) ($row['client_name'] ?? ''),
            'product_category' => (string) ($row['product_category'] ?? ''),
            'nxp_number'       => isset($row['nxp_number']) ? (string) $row['nxp_number'] : null,
            'requested_by_uuid' => (string) $row['requested_by_uuid'],
            'requested_by_name' => (string) ($row['requested_by_name'] ?? ''),
            'requested_at'     => self::ts($row['requested_at']),
            'notice_deadline'  => self::ts($row['notice_deadline']),
            'status'           => (string) $row['status'],
            'created_at'       => self::ts($row['created_at']),
            'updated_at'       => self::ts($row['updated_at']),
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
