<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * inspection_requests — brief §4.
 * A request to inspect a consignment. notice_deadline = requested_at + policy
 * window (the window value is an open question, Q9 — computed in Phase 3).
 */
final class CreateInspectionRequestsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('inspection_requests', $this->tableOptions('Requests to inspect a consignment'));

        $this->addId($table);
        $this->addUuid($table);
        $this->addForeignKeyColumn($table, 'consignment_id', 'consignments');
        $this->addForeignKeyColumn($table, 'requested_by', 'users');

        $table
            ->addColumn('requested_at', 'datetime', ['null' => false])
            ->addColumn('notice_deadline', 'datetime', ['null' => false, 'comment' => 'requested_at + policy window'])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'scheduled', 'completed', 'cancelled'],
                'null'   => false,
                'default' => 'pending',
            ]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['status'], ['name' => 'inspection_requests_status_idx'])
            ->addIndex(['notice_deadline'], ['name' => 'inspection_requests_deadline_idx'])
            ->create();
    }
}
