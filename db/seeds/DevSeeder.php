<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;
use Ramsey\Uuid\Uuid;

/**
 * Development seed data — NOT for production.
 *
 * Creates one super_admin (login-ready, Argon2id hash) and one sample client
 * so Phase 2/3 work has something to authenticate as and reference.
 * Idempotent: skips rows that already exist by unique key.
 *
 *   Login:  admin@adwol.test  /  ChangeMe!123
 */
final class DevSeeder extends AbstractSeed
{
    public function run(): void
    {
        $this->seedSuperAdmin();
        $this->seedSampleClient();
    }

    private function seedSuperAdmin(): void
    {
        if ($this->existsBy('users', 'email', 'admin@adwol.test')) {
            return;
        }

        $this->table('users')->insert([
            'uuid'          => Uuid::uuid4()->toString(),
            'full_name'     => 'Dev Super Admin',
            'email'         => 'admin@adwol.test',
            'phone'         => null,
            'password_hash' => password_hash('ChangeMe!123', PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 1,
            ]),
            'pin_hash'      => null,
            'role'          => 'super_admin',
            'zone'          => null,
            'status'        => 'active',
            'last_login_at' => null,
        ])->saveData();
    }

    private function seedSampleClient(): void
    {
        if ($this->existsBy('clients', 'name', 'Sample Exports Ltd')) {
            return;
        }

        $this->table('clients')->insert([
            'uuid'          => Uuid::uuid4()->toString(),
            'name'          => 'Sample Exports Ltd',
            'type'          => 'exporter',
            'rc_number'     => 'RC123456',
            'address'       => '1 Marina Road, Lagos, Nigeria',
            'contact_name'  => 'A. Contact',
            'contact_phone' => '+2348000000000',
            'contact_email' => 'ops@sample-exports.test',
        ])->saveData();
    }

    /** Parameterized existence check against the live PDO connection. */
    private function existsBy(string $table, string $column, string $value): bool
    {
        /** @var \PDO $pdo */
        $pdo = $this->getAdapter()->getConnection();

        $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}` = :v LIMIT 1");
        $stmt->execute(['v' => $value]);

        return $stmt->fetchColumn() !== false;
    }
}
