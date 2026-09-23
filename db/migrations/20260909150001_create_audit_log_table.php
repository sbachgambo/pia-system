<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * audit_log — brief §4. Append-only at the application layer (§7): no code
 * path issues UPDATE/DELETE against it, and the deployment runbook (Phase 9)
 * grants the app DB user INSERT/SELECT only on this table.
 *
 * No `updated_at` — rows are never modified (explicitly noted in §4).
 * `entity_id` is the internal row id (audit_log is internal-only).
 */
final class CreateAuditLogTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('audit_log', $this->tableOptions('Append-only change log'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'actor_id', 'users', nullable: true); // null = system event

        $table
            ->addColumn('action', 'string', ['limit' => 100, 'null' => false, 'comment' => "e.g. 'inspection.finalize'"])
            ->addColumn('entity_type', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('entity_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('before_state', 'json', ['null' => true])
            ->addColumn('after_state', 'json', ['null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false]);

        $table
            ->addIndex(['entity_type', 'entity_id'], ['name' => 'audit_log_entity_idx'])
            ->addIndex(['action'], ['name' => 'audit_log_action_idx'])
            ->addIndex(['created_at'], ['name' => 'audit_log_created_idx'])
            ->create();
    }
}
