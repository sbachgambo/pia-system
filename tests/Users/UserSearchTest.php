<?php

declare(strict_types=1);

namespace App\Tests\Users;

use App\Tests\Support\DatabaseTestCase;
use App\Users\UserAdminRepository;

/**
 * Regression cover for the user list's search box — the same repeated-named-
 * placeholder fault as ClientSearchTest, on /console/users?q=...
 */
final class UserSearchTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['users'];
    }

    private function seed(): UserAdminRepository
    {
        $repo = new UserAdminRepository($this->pdo);

        $repo->create('33333333-3333-4333-8333-333333333333', [
            'full_name'     => 'Amina Yusuf',
            'email'         => 'amina@adwol.test',
            'phone'         => '+2348020000001',
            'password_hash' => '$argon2id$placeholder',
            'role'          => 'office_reviewer',
            'zone'          => 'Lagos',
            'status'        => 'active',
        ]);
        $repo->create('44444444-4444-4444-8444-444444444444', [
            'full_name'     => 'Chidi Okafor',
            'email'         => 'chidi.amina@adwol.test',
            'phone'         => '+2348020000002',
            'password_hash' => '$argon2id$placeholder',
            'role'          => 'inspector',
            'zone'          => 'Kano',
            'status'        => 'suspended',
        ]);

        return $repo;
    }

    public function testSearchByNameDoesNotThrow(): void
    {
        $result = $this->seed()->paginate(25, 0, ['q' => 'Chidi']);

        self::assertSame(1, $result['total']);
        self::assertSame('Chidi Okafor', $result['rows'][0]['full_name']);
    }

    public function testSearchMatchesEmailAsWellAsName(): void
    {
        // 'amina' is one row's name and the other's email address, so a single
        // term must match on both columns.
        self::assertSame(2, $this->seed()->paginate(25, 0, ['q' => 'amina'])['total']);
    }

    public function testSearchCombinedWithRoleAndStatusFilters(): void
    {
        $repo = $this->seed();

        self::assertSame(1, $repo->paginate(25, 0, ['q' => 'amina', 'role' => 'inspector'])['total']);
        self::assertSame(1, $repo->paginate(25, 0, ['q' => 'amina', 'status' => 'active'])['total']);
        self::assertSame(0, $repo->paginate(25, 0, ['q' => 'amina', 'role' => 'admin'])['total']);
    }

    public function testSearchWithNoMatchesReturnsEmpty(): void
    {
        self::assertSame(0, $this->seed()->paginate(25, 0, ['q' => 'nobody-here'])['total']);
    }
}
