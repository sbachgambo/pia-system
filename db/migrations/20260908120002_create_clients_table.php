<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * clients — brief §4.
 * Exporters/importers whose consignments are inspected. Structured to support
 * multi-tenant later (brief §1) but treated as internal reference data now.
 */
final class CreateClientsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('clients', $this->tableOptions('Exporter / importer records'));

        $this->addId($table);
        $this->addUuid($table);

        $table
            ->addColumn('name', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('type', 'enum', ['values' => ['exporter', 'importer'], 'null' => false])
            ->addColumn('rc_number', 'string', ['limit' => 50, 'null' => true, 'comment' => 'CAC registration'])
            ->addColumn('address', 'text', ['null' => false])
            ->addColumn('contact_name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('contact_phone', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('contact_email', 'string', ['limit' => 190, 'null' => false]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['name'], ['name' => 'clients_name_idx'])
            ->addIndex(['type'], ['name' => 'clients_type_idx'])
            ->create();
    }
}
