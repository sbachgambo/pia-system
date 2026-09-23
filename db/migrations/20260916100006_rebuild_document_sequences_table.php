<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Rebuilds `document_sequences` WITHOUT its own surrogate `id` / AUTO_INCREMENT
 * column — `year` becomes the primary key directly.
 *
 * Why: DocumentNumberAllocator relies on the classic MySQL atomic-counter
 * idiom `INSERT ... ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(...)`.
 * Verified against real MySQL 8: on the ON DUPLICATE KEY UPDATE branch this
 * works exactly as documented. But with a *separate* AUTO_INCREMENT `id`
 * column also present, the very first INSERT for a new year raced against
 * that column's own auto-generated value — `PDO::lastInsertId()` came back
 * as the surrogate `id` (e.g. 4) instead of the explicit `LAST_INSERT_ID(1)`
 * override, allocating "2026-00004" as the FIRST document number of the
 * year instead of "2026-00001". Removing the competing AUTO_INCREMENT
 * column removes the race entirely — this is the standard, documented shape
 * for this idiom. No production data exists yet (Phase 6 mid-build), so this
 * is a clean rebuild rather than a data-preserving ALTER.
 */
final class RebuildDocumentSequencesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('document_sequences')->drop()->save();

        $this->table('document_sequences', [
            'engine'      => 'InnoDB',
            'encoding'    => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'id'          => false,
            'primary_key' => ['year'],
            'comment'     => 'Per-year document numbering counter (Phase 6)',
        ])
            ->addColumn('year', 'smallinteger', ['signed' => false, 'null' => false])
            ->addColumn('next_number', 'integer', ['signed' => false, 'null' => false, 'default' => 1])
            ->addTimestamps('created_at', 'updated_at')
            ->create();
    }

    public function down(): void
    {
        $this->table('document_sequences')->drop()->save();

        $table = $this->table('document_sequences', [
            'engine'      => 'InnoDB',
            'encoding'    => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'id'          => false,
            'primary_key' => ['id'],
            'comment'     => 'Per-year document numbering counter',
        ]);
        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('year', 'smallinteger', ['signed' => false, 'null' => false])
            ->addColumn('next_number', 'integer', ['signed' => false, 'null' => false, 'default' => 1])
            ->addTimestamps('created_at', 'updated_at')
            ->addIndex(['year'], ['unique' => true, 'name' => 'document_sequences_year_uq'])
            ->create();
    }
}
