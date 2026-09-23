<?php

declare(strict_types=1);

use App\Database\MigrationSupport;
use Phinx\Migration\AbstractMigration;

/**
 * Two-factor authentication (TOTP) for console users.
 *
 *  - users.totp_secret        AES-256-GCM box of the base32 seed (see SecretBox); NULL = not enrolled
 *  - users.totp_enabled_at    when enrolment was confirmed
 *  - users.totp_last_step     last accepted 30-second step — a code can't be replayed
 *  - user_recovery_codes      one-time backup codes, stored only as keyed hashes
 *  - operational_settings.require_2fa_admins   admins/super admins must enrol before using the console
 */
final class AddTwoFactor extends AbstractMigration
{
    use MigrationSupport;

    public function change(): void
    {
        $this->table('users')
            ->addColumn('totp_secret', 'string', ['limit' => 255, 'null' => true, 'after' => 'pin_hash'])
            ->addColumn('totp_enabled_at', 'datetime', ['null' => true, 'after' => 'totp_secret'])
            ->addColumn('totp_last_step', 'biginteger', ['signed' => false, 'null' => true, 'after' => 'totp_enabled_at'])
            ->update();

        $codes = $this->table('user_recovery_codes', $this->tableOptions('One-time 2FA recovery codes (hashed)'));
        $this->addId($codes);
        $this->addForeignKeyColumn($codes, 'user_id', 'users', onDelete: 'CASCADE');
        $codes
            ->addColumn('code_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true]);
        $this->addTimestamps($codes);
        $codes->addIndex(['user_id', 'code_hash'], ['unique' => true, 'name' => 'user_recovery_codes_uq'])->create();

        $this->table('operational_settings')
            ->addColumn('require_2fa_admins', 'boolean', ['null' => false, 'default' => false])
            ->update();
    }
}
