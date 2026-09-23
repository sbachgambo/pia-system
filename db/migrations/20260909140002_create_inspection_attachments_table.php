<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * inspection_attachments — brief §4.
 *
 * Photos / documents captured in the field. The binary is uploaded separately
 * from the inspection JSON (large, retried independently — see sync_log
 * record_type 'attachment'). `checksum_sha256` is verified on upload;
 * `uploaded_at` is null until the file actually arrives. `file_path` points at
 * storage OUTSIDE the web root; retrieval is via an authenticated endpoint
 * (DEV-3).
 */
final class CreateInspectionAttachmentsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('inspection_attachments', $this->tableOptions('Field photos / documents'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'inspection_id', 'inspections', onDelete: 'CASCADE');

        $table
            ->addColumn('client_uuid', 'char', ['limit' => 36, 'null' => false, 'comment' => 'client-generated, sync idempotency for the blob'])
            ->addColumn('file_path', 'string', ['limit' => 255, 'null' => true, 'comment' => 'null until the blob is uploaded'])
            ->addColumn('file_type', 'enum', ['values' => ['photo', 'document', 'other'], 'null' => false])
            ->addColumn('original_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('byte_size', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('captured_at', 'datetime', ['null' => false, 'comment' => 'client-side timestamp'])
            ->addColumn('uploaded_at', 'datetime', ['null' => true])
            ->addColumn('checksum_sha256', 'char', ['limit' => 64, 'null' => false]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['client_uuid'], ['unique' => true, 'name' => 'inspection_attachments_client_uuid_uq'])
            ->create();
    }
}
