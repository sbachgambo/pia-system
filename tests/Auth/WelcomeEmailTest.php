<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Audit\AuditLog;
use App\Auth\PasswordHasher;
use App\Auth\PasswordResetService;
use App\Auth\RefreshTokenService;
use App\Auth\UserRepository;
use App\Mail\MailException;
use App\Tests\Support\CollectingMailer;
use App\Tests\Support\DatabaseTestCase;
use App\Users\UserAdminRepository;

/**
 * The welcome email sent when an account is created, so an administrator never
 * has to read a password out to anyone: the new user follows a one-time link
 * and chooses their own.
 */
final class WelcomeEmailTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['password_resets', 'audit_log', 'refresh_tokens', 'users'];
    }

    private function service(CollectingMailer $mailer): PasswordResetService
    {
        return new PasswordResetService(
            $this->pdo,
            new UserRepository($this->pdo),
            new UserAdminRepository($this->pdo),
            new RefreshTokenService($this->pdo, 7),
            new PasswordHasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]),
            $mailer,
            new AuditLog($this->pdo),
            'https://pia.example.test/',
            'ADWOL PIA',
        );
    }

    /** @return array<string,mixed> */
    private function row(array $overrides = []): array
    {
        $user = $this->makeUser($overrides);

        return (array) $this->pdo
            ->query("SELECT * FROM users WHERE id = {$user['id']}")
            ->fetch(\PDO::FETCH_ASSOC);
    }

    public function testOfficeUserIsToldWhereToSignInAndGetsAOneTimeLink(): void
    {
        $mailer = new CollectingMailer();
        $user = $this->row(['role' => 'office_reviewer', 'email' => 'rita@adwol.test', 'full_name' => 'Rita Rev']);

        self::assertTrue($this->service($mailer)->sendWelcome($user, '203.0.113.9'));
        self::assertCount(1, $mailer->sent);

        $body = $mailer->sent[0]['body'];
        self::assertSame('rita@adwol.test', $mailer->sent[0]['to']);
        self::assertStringContainsString('https://pia.example.test/console/login', $body);
        self::assertStringContainsString('rita@adwol.test', $body);
        self::assertStringContainsString('https://pia.example.test/console/reset-password?token=', $body);

        // Only the hash is kept, never the token that went out in the email.
        self::assertSame(1, preg_match('#\?token=([A-Za-z0-9_-]+)#', $body, $m));
        self::assertSame(
            hash('sha256', $m[1]),
            (string) $this->pdo->query('SELECT token_hash FROM password_resets')->fetchColumn(),
        );
    }

    public function testInspectorIsPointedAtTheFieldAppAndReminderAboutThePin(): void
    {
        $mailer = new CollectingMailer();
        $body = '';

        self::assertTrue($this->service($mailer)->sendWelcome($this->row(['role' => 'inspector']), null));
        $body = $mailer->sent[0]['body'];

        self::assertStringContainsString('https://pia.example.test/app/', $body);
        self::assertStringContainsString('PIN', $body);
        self::assertStringNotContainsString('/console/login', $body);
    }

    public function testTheLinkOutlivesAnOrdinaryResetLink(): void
    {
        $mailer = new CollectingMailer();
        $this->service($mailer)->sendWelcome($this->row(['role' => 'admin']), null);

        // A password reset lasts an hour; a new starter may not read their
        // email until the next working day, so this one lasts days.
        $hours = (int) $this->pdo
            ->query('SELECT TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), expires_at) FROM password_resets')
            ->fetchColumn();

        self::assertGreaterThan(24, $hours);
        self::assertLessThanOrEqual(PasswordResetService::WELCOME_TTL_HOURS, $hours);
    }

    public function testNothingIsSentWhenOutgoingEmailIsSwitchedOff(): void
    {
        $mailer = new CollectingMailer(enabled: false);

        self::assertFalse($this->service($mailer)->sendWelcome($this->row(), null));
        self::assertCount(0, $mailer->sent);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn());
    }

    public function testAMailServerFailureSurfacesRatherThanBeingSwallowed(): void
    {
        // Unlike a self-service reset — where silence protects the privacy of
        // the address — an administrator asked for this and must be told it
        // failed, so they can pass the details on another way.
        $this->expectException(MailException::class);

        $this->service(new CollectingMailer(failing: true))->sendWelcome($this->row(), null);
    }
}
