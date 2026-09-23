<?php

declare(strict_types=1);

namespace App\Documents;

use PDO;

/**
 * Prepared-statement access to `consignment_trade_details` (Phase 6, not in
 * brief §4 — see the migration doc-block). 1:1 with `consignments`; upserted
 * as a whole row since the office fills these in together on one form.
 */
final class TradeDetailsRepository
{
    /** @var list<string> */
    private const COLUMNS = [
        'importer_name', 'importer_address', 'nepc_number', 'exporter_bank_name', 'importer_bank_name',
        'bank_reference', 'invoice_number', 'invoice_date', 'basis_of_sale', 'method_of_payment',
        'freight_charges', 'insurance_charges',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function forConsignment(int $consignmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM consignment_trade_details WHERE consignment_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $consignmentId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Insert or replace the whole row for a consignment.
     *
     * @param array<string,mixed> $data column-keyed (any subset of self::COLUMNS; missing = null)
     */
    public function upsert(int $consignmentId, array $data): void
    {
        $placeholders = implode(', ', array_map(static fn (string $c): string => ":{$c}", self::COLUMNS));
        $updates = implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})", self::COLUMNS));

        $sql = 'INSERT INTO consignment_trade_details (consignment_id, ' . implode(', ', self::COLUMNS) . ", created_at, updated_at)
                VALUES (:consignment_id, {$placeholders}, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE {$updates}, updated_at = UTC_TIMESTAMP()";

        $params = ['consignment_id' => $consignmentId];
        foreach (self::COLUMNS as $c) {
            $params[$c] = $data[$c] ?? null;
        }

        $this->pdo->prepare($sql)->execute($params);
    }
}
