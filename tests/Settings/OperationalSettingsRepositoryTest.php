<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\OperationalSettingsRepository;
use App\Tests\Support\DatabaseTestCase;

final class OperationalSettingsRepositoryTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['operational_settings', 'users'];
    }

    public function testFallsBackToEnvDefaultUntilSavedThenUpsertsOneRow(): void
    {
        $repo = new OperationalSettingsRepository($this->pdo, ['compliance_grace_hours' => 24]);
        self::assertSame(24, $repo->get()['compliance_grace_hours']);
        self::assertNull($repo->getWithMeta()['updated_at']);

        $admin = $this->makeUser(['role' => 'admin', 'full_name' => 'Boss']);
        $repo->update(48, $admin['id']);
        $repo->update(72, $admin['id']);

        self::assertSame(72, $repo->get()['compliance_grace_hours']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM operational_settings')->fetchColumn());
        self::assertSame('Boss', $repo->getWithMeta()['updated_by_name']);
    }
}
