<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\ApiException;
use PDO;

/**
 * TOTP two-factor for console users: enrolment, login verification, recovery
 * codes, disabling.
 *
 *  - The seed is stored encrypted (SecretBox); it only exists in plaintext in
 *    memory during a check and, briefly, in the enrolling user's own session.
 *  - A TOTP code is accepted once: the matched 30-second step is recorded with
 *    an atomic compare-and-set, so a code shoulder-surfed or replayed within its
 *    validity window is rejected.
 *  - Recovery codes are random, single-use and stored only as keyed hashes;
 *    generating a new set invalidates the old one.
 */
final class TwoFactorService
{
    public const RECOVERY_CODE_COUNT = 10;
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I

    public function __construct(
        private readonly PDO $pdo,
        private readonly Totp $totp,
        private readonly SecretBox $box,
        private readonly string $appKey,
        private readonly string $issuer,
    ) {
    }

    public function isEnabled(int $userId): bool
    {
        return $this->rowEnabled($userId);
    }

    public function newSecret(): string
    {
        return Totp::generateSecret();
    }

    public function provisioningUri(string $secret, string $accountEmail): string
    {
        return Totp::provisioningUri($secret, $accountEmail, $this->issuer);
    }

    /**
     * Confirm enrolment: the user proves their authenticator has the seed by
     * entering a current code. Returns the plaintext recovery codes — the only
     * time they are ever shown.
     *
     * @return list<string>
     */
    public function enable(int $userId, string $secret, string $code, ?int $now = null): array
    {
        $step = $this->totp->verify($secret, $code, $now ?? time());
        if ($step === null) {
            throw new ApiException(422, 'validation_failed', 'That code is not right. Check the 6 digits in your authenticator app and try again.', ['field' => 'code']);
        }

        $this->pdo->prepare(
            'UPDATE users SET totp_secret = :s, totp_enabled_at = UTC_TIMESTAMP(), totp_last_step = :step, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute(['s' => $this->box->encrypt($secret), 'step' => $step, 'id' => $userId]);

        return $this->regenerateRecoveryCodes($userId);
    }

    /** Check a login code: a current TOTP code, or an unused recovery code. */
    public function verifyLogin(int $userId, string $input, ?int $now = null): bool
    {
        $input = trim($input);
        if ($input === '') {
            return false;
        }

        $compact = str_replace([' ', '-'], '', $input);
        if (preg_match('/^\d{' . Totp::DIGITS . '}$/', $compact) === 1) {
            return $this->verifyTotp($userId, $compact, $now ?? time());
        }

        return $this->consumeRecoveryCode($userId, $input);
    }

    /** @return list<string> plaintext codes, shown once */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $this->pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = :u')->execute(['u' => $userId]);

        $insert = $this->pdo->prepare(
            "INSERT INTO user_recovery_codes (user_id, code_hash, created_at, updated_at) VALUES (:u, :h, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $codes = [];
        while (count($codes) < self::RECOVERY_CODE_COUNT) {
            $raw = '';
            for ($i = 0; $i < 10; $i++) {
                $raw .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            if (isset($codes[$raw])) {
                continue;
            }
            $insert->execute(['u' => $userId, 'h' => $this->hashCode($raw)]);
            $codes[$raw] = $raw;
        }

        return array_map(static fn (string $c): string => substr($c, 0, 5) . '-' . substr($c, 5), array_values($codes));
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL');
        $stmt->execute(['u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Turn 2FA off (self-service after re-authentication, or an admin resetting a lost device). */
    public function disable(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $userId]);
        $this->pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = :u')->execute(['u' => $userId]);
    }

    private function verifyTotp(int $userId, string $code, int $now): bool
    {
        $stmt = $this->pdo->prepare('SELECT totp_secret, totp_last_step FROM users WHERE id = :id AND totp_enabled_at IS NOT NULL');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }

        $secret = $this->box->decrypt((string) $row['totp_secret']);
        if ($secret === null) {
            return false; // unreadable seed (APP_KEY changed?) — fail closed
        }

        $last = $row['totp_last_step'] !== null ? (int) $row['totp_last_step'] : null;
        $step = $this->totp->verify($secret, $code, $now, $last);
        if ($step === null) {
            return false;
        }

        // Atomic compare-and-set: of two concurrent requests presenting the same
        // code, exactly one wins.
        $claim = $this->pdo->prepare(
            'UPDATE users SET totp_last_step = :step WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :step2)'
        );
        $claim->execute(['step' => $step, 'id' => $userId, 'step2' => $step]);

        return $claim->rowCount() === 1;
    }

    private function consumeRecoveryCode(int $userId, string $input): bool
    {
        $normalised = strtoupper(str_replace([' ', '-'], '', $input));
        if (preg_match('/^[A-Z2-9]{10}$/', $normalised) !== 1) {
            return false;
        }

        $use = $this->pdo->prepare(
            'UPDATE user_recovery_codes SET used_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE user_id = :u AND code_hash = :h AND used_at IS NULL'
        );
        $use->execute(['u' => $userId, 'h' => $this->hashCode($normalised)]);

        return $use->rowCount() === 1;
    }

    private function hashCode(string $normalised): string
    {
        return hash_hmac('sha256', $normalised, $this->appKey);
    }

    private function rowEnabled(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :id AND totp_enabled_at IS NOT NULL');
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }
}
