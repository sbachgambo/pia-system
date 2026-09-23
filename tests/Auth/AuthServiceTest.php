<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\AccessTokenService;
use App\Auth\AccountSuspendedException;
use App\Auth\AuthService;
use App\Auth\InvalidCredentialsException;
use App\Auth\OfflineSession;
use App\Auth\PasswordHasher;
use App\Auth\RefreshTokenService;
use App\Auth\UserRepository;
use App\Tests\Support\DatabaseTestCase;

final class AuthServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'users'];
    }

    private function service(): AuthService
    {
        $argon = ['memory_cost' => 65536, 'time_cost' => 2, 'threads' => 1];

        return new AuthService(
            new UserRepository($this->pdo),
            new PasswordHasher($argon),
            new AccessTokenService('test-secret-long-enough-for-hs256-aaaaaaaaaaaaaaaaaaaa', 'pia-api', 900),
            new RefreshTokenService($this->pdo, 7),
            new OfflineSession(7),
        );
    }

    public function testLoginSucceedsAndStampsLastLogin(): void
    {
        $user = $this->makeUser(['email' => 'jane@adwol.test', 'password' => 'Right-Password-1', 'role' => 'admin']);

        $bundle = $this->service()->login('jane@adwol.test', 'Right-Password-1', 'device-9');

        self::assertSame('Bearer', $bundle['token_type']);
        self::assertNotEmpty($bundle['access_token']);
        self::assertNotEmpty($bundle['refresh_token']);
        self::assertSame('admin', $bundle['user']['role']);

        $lastLogin = $this->pdo->query('SELECT last_login_at FROM users WHERE email = "jane@adwol.test"')->fetchColumn();
        self::assertNotNull($lastLogin);

        // The offline block (brief §6): no PIN yet, but a 7-day re-auth deadline
        // anchored on this login.
        self::assertFalse($bundle['offline']['pin_set']);
        self::assertNull($bundle['offline']['pin_hash']);
        self::assertSame(7, $bundle['offline']['reauth_days']);
        $deadline = new \DateTimeImmutable($bundle['offline']['reauth_deadline']);
        $expected = (new \DateTimeImmutable())->modify('+7 days');
        self::assertLessThan(120, abs($deadline->getTimestamp() - $expected->getTimestamp()));
    }

    public function testLoginIsCaseInsensitiveOnEmail(): void
    {
        $this->makeUser(['email' => 'mixed@adwol.test', 'password' => 'Right-Password-1']);

        $bundle = $this->service()->login('  MiXeD@ADWOL.test ', 'Right-Password-1', null);
        self::assertNotEmpty($bundle['access_token']);
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->makeUser(['email' => 'bob@adwol.test', 'password' => 'Right-Password-1']);

        $this->expectException(InvalidCredentialsException::class);
        $this->service()->login('bob@adwol.test', 'nope', null);
    }

    public function testUnknownEmailIsRejectedWithSameError(): void
    {
        $this->expectException(InvalidCredentialsException::class);
        $this->service()->login('ghost@adwol.test', 'whatever', null);
    }

    public function testSuspendedAccountIsRejected(): void
    {
        $this->makeUser(['email' => 'susp@adwol.test', 'password' => 'Right-Password-1', 'status' => 'suspended']);

        $this->expectException(AccountSuspendedException::class);
        $this->service()->login('susp@adwol.test', 'Right-Password-1', null);
    }

    public function testRefreshReturnsNewUsableBundle(): void
    {
        $this->makeUser(['email' => 'kel@adwol.test', 'password' => 'Right-Password-1']);
        $svc = $this->service();

        $login = $svc->login('kel@adwol.test', 'Right-Password-1', 'd1');
        $refreshed = $svc->refresh($login['refresh_token'], 'd1');

        self::assertNotSame($login['refresh_token'], $refreshed['refresh_token']);
        self::assertNotEmpty($refreshed['access_token']);
        self::assertSame('kel@adwol.test', $refreshed['user']['email']);
    }

    public function testTransparentRehashUpgradesAWeakStoredHash(): void
    {
        // Store a deliberately weak Argon2id hash.
        $weak = password_hash('Right-Password-1', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
        $this->makeUser(['email' => 'old@adwol.test', 'password_hash' => $weak]);

        $this->service()->login('old@adwol.test', 'Right-Password-1', null);

        $stored = $this->pdo->query('SELECT password_hash FROM users WHERE email = "old@adwol.test"')->fetchColumn();
        self::assertNotSame($weak, $stored, 'hash should have been upgraded on login');
        self::assertTrue(password_verify('Right-Password-1', (string) $stored));
    }
}
