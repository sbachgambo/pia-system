<?php

declare(strict_types=1);

namespace App\Documents;

use PDO;

/**
 * Prepared-statement access to `inspection_shipment_details` (Phase 6, not in
 * brief §4). 1:1 with `inspections`; filled in by the office during review,
 * once the physical inspection has actually happened.
 */
final class ShipmentDetailsRepository
{
    /** @var list<string> */
    private const COLUMNS = [
        'shipment_date', 'shipping_agent', 'carrier_vessel', 'loading_ref_no', 'container_numbers',
        'packing_details', 'quality_remark', 'gross_weight_kg', 'net_weight_kg', 'forex_exchange_date',
        'exchange_rate', 'ness_charges_paid', 'ness_receipt_no', 'ness_actual_payable', 'ness_balance_paid',
        'ness_balance_receipt_no',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function forInspection(int $inspectionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM inspection_shipment_details WHERE inspection_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $inspectionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Insert or replace the whole row for an inspection.
     *
     * @param array<string,mixed> $data column-keyed (any subset of self::COLUMNS; missing = null)
     */
    public function upsert(int $inspectionId, array $data): void
    {
        $placeholders = implode(', ', array_map(static fn (string $c): string => ":{$c}", self::COLUMNS));
        $updates = implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})", self::COLUMNS));

        $sql = 'INSERT INTO inspection_shipment_details (inspection_id, ' . implode(', ', self::COLUMNS) . ", created_at, updated_at)
                VALUES (:inspection_id, {$placeholders}, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE {$updates}, updated_at = UTC_TIMESTAMP()";

        $params = ['inspection_id' => $inspectionId];
        foreach (self::COLUMNS as $c) {
            $params[$c] = $data[$c] ?? null;
        }

        $this->pdo->prepare($sql)->execute($params);
    }
}
