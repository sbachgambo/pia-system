<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * compliance_tracking — brief §4, Phase 7.
 *
 * One row per (inspector, calendar month). `missed_windows_count` is how many
 * of that inspector's inspections in the month were "missed" (Q9, resolved
 * as A34); `consecutive_miss_count` carries a streak forward from the
 * previous month (reset to 0 the moment a month has zero misses);
 * `alert_triggered` flips on at 3 (the "3-strikes" trigger the brief's
 * column comment refers to). Evaluated by App\Compliance\ComplianceEvaluator,
 * nightly via `bin/evaluate-compliance.php` (brief §8) and on demand.
 */
final class CreateComplianceTrackingTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('compliance_tracking', $this->tableOptions('Per-inspector monthly missed-window tracking'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'inspector_id', 'users');

        $table
            ->addColumn('period_month', 'date', ['null' => false, 'comment' => 'first-of-month marker'])
            ->addColumn('missed_windows_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('consecutive_miss_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('alert_triggered', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('last_evaluated_at', 'datetime', ['null' => false]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['inspector_id', 'period_month'], ['unique' => true, 'name' => 'compliance_tracking_inspector_month_uq'])
            ->addIndex(['alert_triggered'], ['name' => 'compliance_tracking_alert_idx'])
            ->create();
    }
}
