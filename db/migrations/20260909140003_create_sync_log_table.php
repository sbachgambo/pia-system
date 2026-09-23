<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * sync_log — brief §4. One row per record processed by /api/inspections/sync
 * (and by attachment uploads). Gives an audit trail of every device push and
 * how it was resolved.
 */
final class CreateSyncLogTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('sync_log', $this->tableOptions('Per-record sync outcomes'));

        $this->addId($table);

        $table
            ->addColumn('record_uuid', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('record_type', 'string', ['limit' => 50, 'null' => false, 'comment' => "'inspection', 'attachment', ..."]);

        $this->addForeignKeyColumn($table, 'synced_by', 'users');

        $table
            ->addColumn('device_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('sync_status', 'enum', ['values' => ['success', 'conflict', 'error'], 'null' => false])
            ->addColumn('conflict_resolution', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('detail', 'string', ['limit' => 255, 'null' => true, 'comment' => 'error/skip reason for the client'])
            ->addColumn('synced_at', 'datetime', ['null' => false]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['record_uuid'], ['name' => 'sync_log_record_uuid_idx'])
            ->addIndex(['synced_at'], ['name' => 'sync_log_synced_at_idx'])
            ->create();
    }
}
