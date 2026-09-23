<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * consignments — brief §4.
 * A shipment (export or import) belonging to a client. form_nxp_number applies
 * to exports only (enforced at the application layer, not the schema).
 */
final class CreateConsignmentsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('consignments', $this->tableOptions('Shipments submitted for inspection'));

        $this->addId($table);
        $this->addUuid($table);
        $this->addForeignKeyColumn($table, 'client_id', 'clients');

        $table
            ->addColumn('direction', 'enum', ['values' => ['export', 'import'], 'null' => false])
            ->addColumn('product_category', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('product_description', 'text', ['null' => false])
            ->addColumn('hs_code', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('quantity', 'decimal', ['precision' => 14, 'scale' => 2, 'null' => false])
            ->addColumn('unit_of_measure', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('declared_value', 'decimal', ['precision' => 16, 'scale' => 2, 'null' => false])
            ->addColumn('currency', 'char', ['limit' => 3, 'null' => false, 'comment' => 'ISO 4217'])
            ->addColumn('origin_country', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('destination_country', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('zone', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('form_nxp_number', 'string', ['limit' => 50, 'null' => true, 'comment' => 'exports only']);

        $this->addTimestamps($table);

        $table
            ->addIndex(['direction'], ['name' => 'consignments_direction_idx'])
            ->addIndex(['zone'], ['name' => 'consignments_zone_idx'])
            ->create();
    }
}
