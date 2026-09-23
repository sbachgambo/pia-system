<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * inspection_findings — brief §4.
 *
 * Checklist rows captured in the field. A child of `inspections` (no `uuid` of
 * its own — the sync unit is the whole inspection, keyed by inspections.uuid,
 * §3). On sync the finding set is replaced wholesale for its inspection, so
 * CASCADE on delete keeps that clean.
 *
 * Which checklist items are required / how values are validated per product
 * category is open item Q7 — this table stays generic until that lands.
 */
final class CreateInspectionFindingsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('inspection_findings', $this->tableOptions('Field checklist results'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'inspection_id', 'inspections', onDelete: 'CASCADE');

        $table
            ->addColumn('checklist_item', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('expected_value', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('observed_value', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('result', 'enum', ['values' => ['pass', 'fail', 'flag'], 'null' => false])
            ->addColumn('notes', 'text', ['null' => true]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['inspection_id', 'result'], ['name' => 'inspection_findings_inspection_result_idx'])
            ->create();
    }
}
