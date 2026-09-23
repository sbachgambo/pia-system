<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * inspections — brief §4, the heart of the sync model (§3) and D1 workflow.
 *
 *  - `uuid` is CLIENT-generated (UUIDv4) and is the sync idempotency key.
 *    The server never assigns it; `id` is internal and never exposed.
 *  - `status` is D1's state machine:
 *      scheduled -> in_progress -> synced -> amended -> finalized
 *                                        \-> rejected
 *  - `started_at` is the field/device clock; `created_at` (added by the trait)
 *    is server insert time — see DEV_NOTES A8.
 *  - composite index (status, scheduled_at) backs the dashboard queries (§4).
 */
final class CreateInspectionsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('inspections', $this->tableOptions('Field inspections (client-generated uuid, §3)'));

        $this->addId($table);
        $this->addUuid($table); // client-generated; unique guarantees sync idempotency
        $this->addForeignKeyColumn($table, 'inspection_request_id', 'inspection_requests');
        $this->addForeignKeyColumn($table, 'inspector_id', 'users');

        $table
            ->addColumn('scheduled_at', 'datetime', ['null' => false])
            ->addColumn('location_type', 'enum', [
                'values' => ['factory', 'warehouse', 'port', 'other'],
                'null'   => false,
            ])
            ->addColumn('location_detail', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('status', 'enum', [
                'values'  => ['scheduled', 'in_progress', 'synced', 'amended', 'finalized', 'rejected'],
                'null'    => false,
                'default' => 'scheduled',
            ])
            ->addColumn('started_at', 'datetime', ['null' => true, 'comment' => 'device clock'])
            ->addColumn('synced_at', 'datetime', ['null' => true])
            ->addColumn('finalized_at', 'datetime', ['null' => true]);

        $this->addForeignKeyColumn($table, 'finalized_by', 'users', nullable: true);

        $table->addColumn('device_id', 'string', ['limit' => 100, 'null' => true, 'comment' => 'sync_log correlation']);

        $this->addTimestamps($table);

        $table
            ->addIndex(['status', 'scheduled_at'], ['name' => 'inspections_status_scheduled_idx'])
            ->create();
    }
}
