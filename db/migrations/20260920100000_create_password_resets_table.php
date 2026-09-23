<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * password_resets — single-use, expiring links for self-service password reset
 * (console users; only usable when outgoing email is configured). Only the
 * SHA-256 of the token is stored, so a leaked table can't be replayed.
 */
final class CreatePasswordResetsTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('password_resets', $this->tableOptions('Self-service password reset tokens'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'user_id', 'users', onDelete: 'CASCADE');

        $table
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'password_resets_token_uq'])
            ->create();
    }
}
