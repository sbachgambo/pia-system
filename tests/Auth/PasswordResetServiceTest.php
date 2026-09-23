<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Audit\AuditLog;
use App\Auth\PasswordHasher;
use App\Auth\PasswordResetService;
use App\Auth\RefreshTokenService;
use App\Auth\UserRepository;
use App\Support\ApiException;
use App\Tests\Support\CollectingMailer;
use App\Tests\Support\DatabaseTestCase;
use App\Users\UserAdminRepository;

final class PasswordResetServiceTest extends DatabaseTestCase
{
    private PasswordHasher $hasher;

    protected function dirtyTables(): array
    {
        return ['password_resets', 'audit_log', 'refresh_tokens', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new PasswordHasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
    }

    private function service(CollectingMailer $mailer): PasswordResetService
    {
        return new PasswordResetService(
            $this->pdo,
            new UserRepository($this->pdo),
            new UserAdminRepository($this->pdo),
            new RefreshTokenService($this->pdo, 7),
            $this->hasher,
            $mailer,
            new AuditLog($this->pdo),
            'https://pia.example.test/',
            'ADWOL PIA',
        );
    }

    private function tokenFrom(CollectingMailer $mailer, int $index = 0): string
    {
        self::assertSame(1, preg_match('#https://pia\.example\.test/console/reset-password\?token=([A-Za-z0-9_-]+)#', $mailer->sent[$index]['body'], $m));

        return $m[1];
    }

    public function testRequestSendsALinkBuiltFromConfiguredUrlAndStoresOnlyTheHash(): void
    {
        $user = $this->makeUser(['role' => 'office_reviewer', 'email' => 'rev@adwol.test', 'full_name' => 'Rita Rev']);
        $mailer = new CollectingMailer();

        $this->service($mailer)->request('  REV@adwol.test ', '203.0.113.5');

        self::assertCount(1, $mailer->sent);
        self::assertSame('rev@adwol.test', $mailer->sent[0]['to']);
        $raw = $this->tokenFrom($mailer);
        $stored = (string) $this->pdo->query('SELECT token_hash FROM password_resets')->fetchColumn();
        self::assertSame(hash('sha256', $raw), $stored);
        self::assertStringNotContainsString($raw, $stored);
        self::assertSame($user['id'], (int) $this->pdo->query('SELECT user_id FROM password_resets')->fetchColumn());
    }

    public function testNothingIsSentForUnknownSuspendedInspectorOrWhenEmailIsOff(): void
    {
        $this->makeUser(['role' => 'office_reviewer', 'email' => 'gone@adwol.test', 'status' => 'suspended']);
        $this->makeUser(['role' => 'inspector', 'email' => 'field@adwol.test']);
        $this->makeUser(['role' => 'admin', 'email' => 'ok@adwol.test']);
        $mailer = new CollectingMailer();
        $svc = $this->service($mailer);

        foreach (['nobody@adwol.test', 'gone@adwol.test', 'field@adwol.test'] as $e) {
            $svc->request($e, null);
        }
        (new PasswordResetService(
            $this->pdo, new UserRepository($this->pdo), new UserAdminRepository($this->pdo), new RefreshTokenService($this->pdo, 7),
            $this->hasher, new CollectingMailer(false), new AuditLog($this->pdo), 'https://x.test', 'X',
        ))->request('ok@adwol.test', null);

        self::assertSame([], $mailer->sent);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
    }

    public function testRequestsAreThrottledPerUserAndAMailFailureIsSwallowed(): void
    {
        $this->makeUser(['role' => 'admin', 'email' => 'a@adwol.test']);
        $mailer = new CollectingMailer();
        $svc = $this->service($mailer);

        for ($i = 0; $i < 5; $i++) {
            $svc->request('a@adwol.test', null);
        }
        self::assertCount(3, $mailer->sent);

        $this->service(new CollectingMailer(true, true))->request('a@adwol.test', null);
        $this->addToAssertionCount(1); // no exception escaped
    }

    public function testCompleteChangesThePasswordBurnsTheLinkAndEndsSessions(): void
    {
        $user = $this->makeUser(['role' => 'office_reviewer', 'email' => 'r@adwol.test', 'password' => 'Old-Password-1']);
        $mailer = new CollectingMailer();
        $svc = $this->service($mailer);
        $svc->request('r@adwol.test', null);
        $svc->request('r@adwol.test', null);
        $first = $this->tokenFrom($mailer, 0);
        $second = $this->tokenFrom($mailer, 1);

        self::assertSame($user['id'], $svc->userForToken($first)['id']);
        $svc->complete($first, 'A-Brand-New-Password', '198.51.100.7');

        $hash = (string) $this->pdo->query("SELECT password_hash FROM users WHERE id = {$user['id']}")->fetchColumn();
        self::assertTrue($this->hasher->verify('A-Brand-New-Password', $hash));
        self::assertNotNull($this->pdo->query("SELECT sessions_valid_after FROM users WHERE id = {$user['id']}")->fetchColumn());
        self::assertNull($svc->userForToken($first), 'the used link is dead');
        self::assertNull($svc->userForToken($second), 'other outstanding links are invalidated too');
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'user.password_reset_self'")->fetchColumn());

        try {
            $svc->complete($first, 'Another-Password-99', null);
            self::fail('a link must work only once');
        } catch (ApiException $e) {
            self::assertSame('invalid_token', $e->getErrorCode());
        }
    }

    public function testWeakPasswordDoesNotBurnTheLinkAndExpiredOrBogusTokensAreRejected(): void
    {
        $this->makeUser(['role' => 'admin', 'email' => 'w@adwol.test']);
        $mailer = new CollectingMailer();
        $svc = $this->service($mailer);
        $svc->request('w@adwol.test', null);
        $token = $this->tokenFrom($mailer);

        try {
            $svc->complete($token, 'short', null);
            self::fail('expected weak-password rejection');
        } catch (ApiException $e) {
            self::assertSame('validation_failed', $e->getErrorCode());
        }
        self::assertNotNull($svc->userForToken($token), 'the link survives a rejected password');

        $this->pdo->exec('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        self::assertNull($svc->userForToken($token), 'expired');
        self::assertNull($svc->userForToken('not-a-real-token'));
        self::assertNull($svc->userForToken(''));
        self::assertNull($svc->userForToken(str_repeat('a', 200)));
    }
}
