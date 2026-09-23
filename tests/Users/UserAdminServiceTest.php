<?php

declare(strict_types=1);

namespace App\Tests\Users;

use App\Auth\PasswordHasher;
use App\Auth\RefreshTokenService;
use App\Http\Support\Input;
use App\Office\NotFoundException;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use App\Users\UserAdminRepository;
use App\Users\UserAdminService;

final class UserAdminServiceTest extends DatabaseTestCase
{
    private UserAdminService $svc;
    private PasswordHasher $hasher;

    protected function dirtyTables(): array
    {
        return ['users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new PasswordHasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
        $this->svc = new UserAdminService(new UserAdminRepository($this->pdo), $this->hasher, new RefreshTokenService($this->pdo, 7));
    }

    /** @param array<string,mixed> $over */
    private function payload(array $over = []): Input
    {
        return Input::fromArray($over + [
            'full_name' => 'New Person', 'email' => 'New.Person@Adwol.test', 'role' => 'inspector',
            'zone' => 'Lagos', 'password' => 'a-long-enough-pass',
        ]);
    }

    private function storedHash(string $uuid): string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE uuid = :u');
        $stmt->execute(['u' => $uuid]);

        return (string) $stmt->fetchColumn();
    }

    public function testCreateStoresAHashedPasswordAndLowercasesTheEmail(): void
    {
        $u = $this->svc->create($this->payload());

        self::assertSame('new.person@adwol.test', $u['email']);
        self::assertSame('active', $u['status']);
        self::assertArrayNotHasKey('password_hash', $u);
        self::assertTrue($this->hasher->verify('a-long-enough-pass', $this->storedHash($u['uuid'])));
    }

    public function testCreateRejectsDuplicateEmailShortPasswordAndBadRole(): void
    {
        $this->svc->create($this->payload());

        foreach ([
            ['email' => 'new.person@adwol.test'],
            ['email' => 'other@adwol.test', 'password' => 'short'],
            ['email' => 'other@adwol.test', 'role' => 'root'],
        ] as $bad) {
            try {
                $this->svc->create($this->payload($bad));
                self::fail('expected validation failure for ' . json_encode($bad));
            } catch (ApiException $e) {
                self::assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function testUpdateChangesRoleButAllowsKeepingOwnEmail(): void
    {
        $u = $this->svc->create($this->payload());

        $updated = $this->svc->update($u['uuid'], Input::fromArray(['email' => 'new.person@adwol.test', 'role' => 'office_reviewer']));

        self::assertSame('office_reviewer', $updated['role']);
    }

    public function testResetPasswordReplacesTheHashAndEnforcesMinimumLength(): void
    {
        $u = $this->svc->create($this->payload());

        $this->svc->resetPassword($u['uuid'], 'brand-new-password');
        $hash = $this->storedHash($u['uuid']);
        self::assertTrue($this->hasher->verify('brand-new-password', $hash));
        self::assertFalse($this->hasher->verify('a-long-enough-pass', $hash));

        $this->expectException(ApiException::class);
        $this->svc->resetPassword($u['uuid'], 'tiny');
    }

    public function testResetSuspendAndSignOutAllStampSessionsValidAfter(): void
    {
        $u = $this->svc->create($this->payload());
        $stamp = fn (): ?string => $this->pdo->query("SELECT sessions_valid_after FROM users WHERE uuid = '{$u['uuid']}'")->fetchColumn() ?: null;

        self::assertNull($stamp());
        $this->svc->resetPassword($u['uuid'], 'brand-new-password');
        self::assertNotNull($stamp(), 'password reset ends existing sessions');

        $this->pdo->exec("UPDATE users SET sessions_valid_after = NULL");
        $this->svc->setStatus($u['uuid'], 'suspended');
        self::assertNotNull($stamp(), 'suspension ends existing sessions');

        $this->pdo->exec("UPDATE users SET sessions_valid_after = NULL");
        $this->svc->setStatus($u['uuid'], 'active');
        self::assertNull($stamp(), 're-activation does not');

        $this->svc->signOutEverywhere($u['uuid']);
        self::assertNotNull($stamp());
    }

    public function testSetStatusSuspendsAndReactivatesAndUnknownUserIs404(): void
    {
        $u = $this->svc->create($this->payload());

        self::assertSame('suspended', $this->svc->setStatus($u['uuid'], 'suspended')['status']);
        self::assertSame('active', $this->svc->setStatus($u['uuid'], 'active')['status']);

        $this->expectException(NotFoundException::class);
        $this->svc->setStatus('00000000-0000-0000-0000-000000000000', 'suspended');
    }
}
