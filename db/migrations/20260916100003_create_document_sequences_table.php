<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * document_sequences — DEV-4 (anticipated in Phase 1's dev notes), built in
 * Phase 6. One counter row per calendar year, shared across every document
 * type. Confirmed by the client's real CCI numbers (sample-docs/2021-00002.pdf
 * = "2021-00002"; the October appendix runs through "2021-00302") — a single
 * running sequence per year, not per month, not per type (Q10, resolved).
 *
 * Allocation is a single atomic statement (App\Documents\DocumentNumberAllocator):
 *   INSERT ... ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number + 1)
 * which is race-safe under MySQL without a separate SELECT ... FOR UPDATE.
 */
final class CreateDocumentSequencesTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('document_sequences', $this->tableOptions('Per-year document numbering counter'));

        $this->addId($table);

        $table
            ->addColumn('year', 'smallinteger', ['signed' => false, 'null' => false])
            ->addColumn('next_number', 'integer', ['signed' => false, 'null' => false, 'default' => 1]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['year'], ['unique' => true, 'name' => 'document_sequences_year_uq'])
            ->create();
    }
}
