<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLog;
use App\Http\Console\ConsoleAuth;
use App\Mail\MailerInterface;
use App\Mail\MailException;
use App\Support\ApiException;
use App\Users\UserAdminRepository;
use App\Users\UserAdminService;
use PDO;

/**
 * Self-service password reset for console users, by emailed link.
 *
 *  - Only works when outgoing email is configured (otherwise `request()` is a
 *    no-op and the UI never offers it).
 *  - `request()` never reveals whether an address is registered: it always
 *    "succeeds", and only sends for an active office-role account.
 *  - The link carries 32 random bytes; only their SHA-256 is stored, the link
 *    lives 60 minutes, and it works once. Completing a reset also invalidates
 *    every other outstanding link and ends all of the user's live sessions.
 *  - The link is built from the configured APP_URL, never the request's Host
 *    header (Host-header poisoning would otherwise let an attacker mail a
 *    victim a link to their own domain).
 */
final class PasswordResetService
{
    public const TTL_MINUTES = 60;
    public const WELCOME_TTL_HOURS = 168;   // 7 days — a new starter may not read email today
    private const MAX_REQUESTS_PER_HOUR = 3;

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly UserAdminRepository $userAdmin,
        private readonly RefreshTokenService $refreshTokens,
        private readonly PasswordHasher $hasher,
        private readonly MailerInterface $mailer,
        private readonly AuditLog $audit,
        private readonly string $baseUrl,
        private readonly string $appName,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->mailer->isEnabled();
    }

    public function request(string $email, ?string $ip): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $user = $this->users->findByEmail(strtolower(trim($email)));
        if ($user === null || $user['status'] !== 'active' || !in_array($user['role'], ConsoleAuth::ROLES, true)) {
            return;
        }

        $recent = $this->pdo->prepare(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = :u AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)'
        );
        $recent->execute(['u' => $user['id']]);
        if ((int) $recent->fetchColumn() >= self::MAX_REQUESTS_PER_HOUR) {
            return;
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address, created_at, updated_at)
             VALUES (:u, :h, UTC_TIMESTAMP() + INTERVAL ' . self::TTL_MINUTES . ' MINUTE, :ip, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute(['u' => $user['id'], 'h' => hash('sha256', $raw), 'ip' => $ip]);

        $link = rtrim($this->baseUrl, '/') . '/console/reset-password?token=' . $raw;

        try {
            $this->mailer->send(
                (string) $user['email'],
                (string) $user['full_name'],
                "Reset your {$this->appName} password",
                "Hello {$user['full_name']},\n\n"
                . "Someone asked to reset the password for your {$this->appName} account. If that was you, choose a new password here:\n\n"
                . "{$link}\n\n"
                . 'This link works once and expires in ' . self::TTL_MINUTES . " minutes.\n\n"
                . "If you did not ask for this, ignore this email — your password stays as it is.\n",
            );
        } catch (MailException) {
            // Already logged by the mailer. Swallowed on purpose: surfacing a
            // mail failure here would tell the requester the address exists.
        }
    }

    /**
     * Welcome a newly created account: mail the person the address they sign
     * in with, where to sign in, and a link to choose their own password.
     *
     * This exists so nobody has to read a password down the phone or send one
     * over WhatsApp — the administrator who creates the account never learns
     * it. Unlike request(), it is triggered by an administrator on an account
     * they just created, so it says plainly whether it sent.
     *
     * The link lives WELCOME_TTL_HOURS rather than an hour: a new colleague
     * may not read their email until the next working day. It can be sent
     * again from the user's page if it lapses.
     *
     * @param array<string,mixed> $user a users row (id, email, full_name, role)
     * @return bool false when outgoing email is switched off
     */
    public function sendWelcome(array $user, ?string $ip): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address, created_at, updated_at)
             VALUES (:u, :h, UTC_TIMESTAMP() + INTERVAL ' . self::WELCOME_TTL_HOURS . ' HOUR, :ip, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute(['u' => (int) $user['id'], 'h' => hash('sha256', $raw), 'ip' => $ip]);

        $base = rtrim($this->baseUrl, '/');
        $link = $base . '/console/reset-password?token=' . $raw;

        // Inspectors work in the field app; everyone else in the office console.
        $where = $user['role'] === 'inspector'
            ? "the inspector app at {$base}/app/"
            : "the office console at {$base}/console/login";

        $this->mailer->send(
            (string) $user['email'],
            (string) $user['full_name'],
            "Your {$this->appName} account",
            "Hello {$user['full_name']},\n\n"
            . "An account has been created for you on {$this->appName}.\n\n"
            . "Sign in at " . $where . "\n"
            . "Your username is your email address: {$user['email']}\n\n"
            . "Choose your password here first:\n\n"
            . "{$link}\n\n"
            . 'This link works once and expires in ' . (int) (self::WELCOME_TTL_HOURS / 24) . " days. "
            . "If it expires, ask an administrator to send it again.\n\n"
            . ($user['role'] === 'inspector'
                ? "Once you are signed in, set a PIN on your device so you can unlock the app offline.\n"
                : '')
            . "\nIf you were not expecting this, tell your administrator.\n",
        );

        return true;
    }

    /** @return array{id:int,email:string,full_name:string}|null */
    public function userForToken(string $rawToken): ?array
    {
        $row = $this->tokenRow($rawToken);

        return $row === null ? null : ['id' => (int) $row['user_id'], 'email' => (string) $row['email'], 'full_name' => (string) $row['full_name']];
    }

    public function complete(string $rawToken, string $newPassword, ?string $ip): void
    {
        $row = $this->tokenRow($rawToken);
        if ($row === null) {
            throw new ApiException(422, 'invalid_token', 'This reset link is invalid or has expired. Request a new one.');
        }
        if (mb_strlen($newPassword) < UserAdminService::MIN_PASSWORD_LENGTH) {
            throw new ApiException(
                422,
                'validation_failed',
                'Password must be at least ' . UserAdminService::MIN_PASSWORD_LENGTH . ' characters.',
                ['field' => 'password'],
            );
        }

        // Atomically burn this link; a concurrent second use loses the race.
        $claim = $this->pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL');
        $claim->execute(['id' => $row['id']]);
        if ($claim->rowCount() !== 1) {
            throw new ApiException(422, 'invalid_token', 'This reset link is invalid or has expired. Request a new one.');
        }

        $userId = (int) $row['user_id'];
        $this->users->updatePasswordHash($userId, $this->hasher->hash($newPassword));
        $this->userAdmin->revokeSessions($userId);
        $this->refreshTokens->revokeAllForUser($userId);
        $this->pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE user_id = :u AND used_at IS NULL')
            ->execute(['u' => $userId]);

        $this->audit->record($userId, 'user.password_reset_self', 'user', $userId, null, ['via' => 'email link'], $ip);
    }

    /** @return array<string,mixed>|null */
    private function tokenRow(string $rawToken): ?array
    {
        if ($rawToken === '' || strlen($rawToken) > 100) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT pr.id, pr.user_id, u.email, u.full_name
               FROM password_resets pr JOIN users u ON u.id = pr.user_id
              WHERE pr.token_hash = :h AND pr.used_at IS NULL AND pr.expires_at > UTC_TIMESTAMP() AND u.status = 'active'
              LIMIT 1"
        );
        $stmt->execute(['h' => hash('sha256', $rawToken)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
