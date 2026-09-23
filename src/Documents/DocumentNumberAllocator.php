<?php

declare(strict_types=1);

namespace App\Documents;

use PDO;

/**
 * Allocates the human-facing `document_number` (Q10, resolved from the
 * client's real CCI samples): "{year}-{5-digit sequence}", e.g. "2026-00001".
 * One counter per calendar year, shared across every document type — that is
 * exactly what the sample CCIs show (2021-00002 in February running through
 * 2021-00302 by late October; no monthly reset, no per-type split).
 *
 * Allocation is a single atomic UPSERT so concurrent finalizes can never
 * collide (`document_sequences.year` is unique; see DEV-4 / DEV-12):
 *
 *   INSERT ... ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1)
 *
 * `LAST_INSERT_ID(expr)` is a MySQL idiom that makes `PDO::lastInsertId()`
 * return the *allocated* number whether this was a fresh row (1) or an
 * existing one (next_number + 1) — no separate SELECT ... FOR UPDATE needed.
 */
final class DocumentNumberAllocator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $digits = 5,
    ) {
    }

    /** @param int $year e.g. (int) gmdate('Y') */
    public function allocate(int $year): string
    {
        // document_sequences has its own AUTO_INCREMENT `id` (unrelated to the
        // counter), so both branches must explicitly wrap the value in
        // LAST_INSERT_ID(...) — otherwise a fresh row (first CCI of the year)
        // would make PDO::lastInsertId() return the `id` column instead of
        // `next_number`.
        $stmt = $this->pdo->prepare(
            'INSERT INTO document_sequences (year, next_number, created_at, updated_at)
             VALUES (:year, LAST_INSERT_ID(1), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1), updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute(['year' => $year]);

        $sequence = (int) $this->pdo->lastInsertId();

        return sprintf('%d-%0' . $this->digits . 'd', $year, $sequence);
    }
}
