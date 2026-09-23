<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * users — brief §4.
 * Roles drive authorization (Phase 2+); last_login_at drives the D4 7-day
 * offline re-auth rule; pin_hash (nullable) is the Argon2id offline PIN.
 */
final class CreateUsersTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('users', $this->tableOptions('Application users / inspectors'));

        $this->addId($table);
        $this->addUuid($table);

        $table
            ->addColumn('full_name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 190, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false, 'comment' => 'Argon2id'])
            ->addColumn('pin_hash', 'string', ['limit' => 255, 'null' => true, 'comment' => 'Argon2id offline PIN (D4)'])
            ->addColumn('role', 'enum', [
                'values' => ['inspector', 'office_reviewer', 'admin', 'super_admin'],
                'null'   => false,
            ])
            ->addColumn('zone', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('status', 'enum', [
                'values'  => ['active', 'suspended'],
                'null'    => false,
                'default' => 'active',
            ])
            ->addColumn('last_login_at', 'datetime', ['null' => true, 'comment' => 'drives the D4 7-day re-auth rule']);

        $this->addTimestamps($table);

        $table
            ->addIndex(['email'], ['unique' => true, 'name' => 'users_email_uq'])
            ->addIndex(['role'], ['name' => 'users_role_idx'])
            ->addIndex(['status'], ['name' => 'users_status_idx'])
            ->create();
    }
}
