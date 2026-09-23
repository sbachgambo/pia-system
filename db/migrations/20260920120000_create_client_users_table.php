<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * client_users — logins for the client self-service portal (/portal).
 *
 * Deliberately a separate table from `users`: portal accounts are external
 * people, belong to exactly one client, have no role, and can never reach the
 * console or the inspector API — keeping them out of `users` means a mistake in
 * role handling can't promote one. Created by an admin from the client's page.
 */
final class CreateClientUsersTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('client_users', $this->tableOptions('Client self-service portal logins'));

        $this->addId($table);
        $this->addUuid($table);
        $this->addForeignKeyColumn($table, 'client_id', 'clients');

        $table
            ->addColumn('full_name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false, 'comment' => 'Argon2id'])
            ->addColumn('status', 'enum', ['values' => ['active', 'suspended'], 'null' => false, 'default' => 'active'])
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->addColumn('sessions_valid_after', 'datetime', ['null' => true]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['email'], ['unique' => true, 'name' => 'client_users_email_uq'])
            ->create();
    }
}
