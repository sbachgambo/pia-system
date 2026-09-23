<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * The client self-service portal was withdrawn (client decision, Sep 2026):
 * clients no longer sign in to the system. Drops the portal's login table.
 * `down()` recreates it empty — the accounts themselves are not recoverable.
 */
final class DropClientPortal extends AbstractMigration
{
    use MigrationSupport;

    public function up(): void
    {
        $this->table('client_users')->drop()->save();
    }

    public function down(): void
    {
        $table = $this->table('client_users', $this->tableOptions('Client self-service portal logins'));

        $this->addId($table);
        $this->addUuid($table);
        $this->addForeignKeyColumn($table, 'client_id', 'clients');

        $table
            ->addColumn('full_name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('status', 'enum', ['values' => ['active', 'suspended'], 'null' => false, 'default' => 'active'])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('sessions_valid_after', 'datetime', ['null' => true]);

        $this->addTimestamps($table);

        $table->addIndex(['email'], ['unique' => true, 'name' => 'client_users_email_uq'])->create();
    }
}
