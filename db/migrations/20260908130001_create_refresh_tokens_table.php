<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * refresh_tokens (DEV-1) — server-side state for D4.
 *
 * The raw refresh token is opaque random bytes handed to the client; only its
 * SHA-256 hash is stored here. Tokens rotate on every use:
 *   - `family_id` groups a rotation chain that all descend from ONE full login.
 *   - `login_at` is that login's timestamp — the D4 anchor. A chain's
 *     `expires_at` is capped at login_at + the 7-day re-auth window, so
 *     refreshing cannot extend a session forever; after 7 days the client
 *     must log in online again.
 *   - `used_at` is set when a token is rotated. A second presentation of an
 *     already-used token is a theft signal: the whole family is revoked.
 */
final class CreateRefreshTokensTable extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $table = $this->table('refresh_tokens', $this->tableOptions('Rotating refresh tokens (D4)'));

        $this->addId($table);
        $this->addForeignKeyColumn($table, 'user_id', 'users', onDelete: 'CASCADE');

        $table
            ->addColumn('family_id', 'char', ['limit' => 36, 'null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'SHA-256 of the opaque token'])
            ->addColumn('login_at', 'datetime', ['null' => false, 'comment' => 'full-login anchor for the 7-day cap (D4)'])
            ->addColumn('issued_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true, 'comment' => 'set on rotation; reuse => revoke family'])
            ->addColumn('replaced_by', 'char', ['limit' => 64, 'null' => true, 'comment' => 'token_hash of successor'])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('device_id', 'string', ['limit' => 100, 'null' => true]);

        $this->addTimestamps($table);

        $table
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'refresh_tokens_hash_uq'])
            ->addIndex(['family_id'], ['name' => 'refresh_tokens_family_idx'])
            ->addIndex(['expires_at'], ['name' => 'refresh_tokens_expires_idx'])
            ->create();
    }
}
