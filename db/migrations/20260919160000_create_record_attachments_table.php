<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * record_attachments — office-uploaded supporting documents (RC certificate,
 * invoices, permits…) on a client or a consignment. Distinct from
 * `inspection_attachments`, which are inspector photos synced from the field.
 *
 * `entity_id` is polymorphic (client id or consignment id, per `entity_type`),
 * so it carries no foreign key; the app validates the target before insert.
 * Binaries live outside the web root under storage/record-attachments/.
 */
final class CreateRecordAttachmentsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('record_attachments', $this->tableOptions('Office documents attached to clients / consignments'));

        $this->addId($table);
        $this->addUuid($table);
        $this->addForeignKeyColumn($table, 'uploaded_by', 'users');

        $table
            ->addColumn('entity_type', 'enum', ['values' => ['client', 'consignment'], 'null' => false])
            ->addColumn('entity_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('original_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('file_path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('byte_size', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('checksum_sha256', 'char', ['limit' => 64, 'null' => false]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['entity_type', 'entity_id'], ['name' => 'record_attachments_entity_idx'])
            ->create();
    }
}
