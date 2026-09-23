<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * statutory_returns — brief §4/§5/§8, Phase 7.
 *
 * `format` is CSV for every builder today (D3: "generated PDF/CSV reports
 * for manual submission" — CSV chosen over replicating multi-sheet Excel
 * pivots, which isn't reliably buildable without a real spreadsheet writer;
 * see A35/A36). `submitted_at`/`submitted_by` are filled in later, by hand,
 * once someone has actually handed the file to the regulator (§4: "manually
 * confirmed") — there's no automated submission channel (D3's rationale).
 */
final class CreateStatutoryReturnsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('statutory_returns', $this->tableOptions('Generated regulator returns'));

        $this->addId($table);
        $this->addUuid($table);
        $this->addForeignKeyColumn($table, 'generated_by', 'users', nullable: true);
        $this->addForeignKeyColumn($table, 'submitted_by', 'users', nullable: true);

        $table
            ->addColumn('agency', 'enum', ['values' => ['CBN', 'NEPC', 'NBS', 'MOF', 'CUSTOMS'], 'null' => false])
            ->addColumn('period_start', 'date', ['null' => false])
            ->addColumn('period_end', 'date', ['null' => false])
            ->addColumn('file_path', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('format', 'enum', ['values' => ['pdf', 'csv'], 'null' => false, 'default' => 'csv'])
            ->addColumn('row_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0, 'comment' => 'not in §4 — how many register rows this return covers'])
            ->addColumn('generated_at', 'datetime', ['null' => false])
            ->addColumn('submitted_at', 'datetime', ['null' => true])
            ->addColumn('status', 'enum', ['values' => ['generated', 'submitted'], 'null' => false, 'default' => 'generated']);

        $this->addTimestamps($table);

        $table
            ->addIndex(['agency', 'period_start', 'period_end'], ['name' => 'statutory_returns_agency_period_idx'])
            ->addIndex(['status'], ['name' => 'statutory_returns_status_idx'])
            ->create();
    }
}
