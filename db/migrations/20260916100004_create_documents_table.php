<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * documents — brief §4, built in Phase 6.
 *
 * `type` is widened to `CCI/NNCI/CRF/IDR` (brief §4 only lists CCI/CRF/IDR).
 * The client's real report (sample-docs, Oct 2021 report §5.3) issues a
 * second, evidenced document type the brief didn't name: NNCI ("Non-
 * Negotiable Certificate of Inspection" — issued instead of a CCI when the
 * NESS fee hasn't been paid). CRF/IDR are kept as accepted values for
 * forward compatibility, but only CCI/NNCI have a renderer today
 * (App\Documents\CciDocumentBuilder) — additive, not a removal (DEV-12).
 *
 * `content_hash` (SHA-256) + `hmac_signature` are D7's tamper-evidence.
 * `document_number` is the human-facing "2021-00002"-style number allocated
 * by document_sequences.
 */
final class CreateDocumentsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('documents', $this->tableOptions('Generated CCI/NNCI/CRF/IDR documents'));

        $this->addId($table);
        $this->addUuid($table);
        $this->addForeignKeyColumn($table, 'inspection_id', 'inspections', onDelete: 'RESTRICT');
        $this->addForeignKeyColumn($table, 'issued_by', 'users', nullable: true);

        $table
            ->addColumn('type', 'enum', ['values' => ['CCI', 'NNCI', 'CRF', 'IDR'], 'null' => false])
            ->addColumn('document_number', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('file_path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('content_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'SHA-256 (D7)'])
            ->addColumn('hmac_signature', 'string', ['limit' => 255, 'null' => false, 'comment' => 'D7'])
            ->addColumn('issued_at', 'datetime', ['null' => false])
            ->addColumn('status', 'enum', ['values' => ['draft', 'issued', 'void'], 'null' => false, 'default' => 'issued']);

        $this->addTimestamps($table);

        $table
            ->addIndex(['document_number'], ['unique' => true, 'name' => 'documents_number_uq'])
            ->addIndex(['type'], ['name' => 'documents_type_idx'])
            ->create();
    }
}
