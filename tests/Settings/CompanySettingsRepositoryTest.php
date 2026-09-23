<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\CompanySettingsRepository;
use App\Tests\Support\DatabaseTestCase;

final class CompanySettingsRepositoryTest extends DatabaseTestCase
{
    private const DEFAULTS = [
        'name' => 'ADWOL Investments and Services', 'address' => '', 'representative_title' => 'Authorised Representative',
    ];

    protected function dirtyTables(): array
    {
        return ['company_settings', 'users'];
    }

    public function testGetFallsBackToTheEnvDefaultWhenNothingHasBeenSaved(): void
    {
        $repo = new CompanySettingsRepository($this->pdo, self::DEFAULTS);

        self::assertSame(self::DEFAULTS, $repo->get());
    }

    public function testUpdateThenGetReturnsTheSavedValue(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $repo = new CompanySettingsRepository($this->pdo, self::DEFAULTS);

        $repo->update(['name' => 'New Co Ltd', 'address' => '1 Main St', 'representative_title' => 'Managing Director'], $user['id']);

        self::assertSame([
            'name' => 'New Co Ltd', 'address' => '1 Main St', 'representative_title' => 'Managing Director',
        ], $repo->get());
    }

    public function testUpdateIsIdempotentUpsertNotADuplicateRow(): void
    {
        $repo = new CompanySettingsRepository($this->pdo, self::DEFAULTS);

        $repo->update(['name' => 'First', 'address' => null, 'representative_title' => 'Rep'], null);
        $repo->update(['name' => 'Second', 'address' => null, 'representative_title' => 'Rep'], null);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM company_settings')->fetchColumn());
        self::assertSame('Second', $repo->get()['name']);
    }

    public function testGetWithMetaReportsWhoChangedItAndWhen(): void
    {
        $user = $this->makeUser(['role' => 'super_admin', 'full_name' => 'Jane Admin']);
        $repo = new CompanySettingsRepository($this->pdo, self::DEFAULTS);

        self::assertNull($repo->getWithMeta()['updated_at']);

        $repo->update(['name' => 'X', 'address' => null, 'representative_title' => 'Rep'], $user['id']);

        $meta = $repo->getWithMeta();
        self::assertNotNull($meta['updated_at']);
        self::assertSame('Jane Admin', $meta['updated_by_name']);
    }
}
