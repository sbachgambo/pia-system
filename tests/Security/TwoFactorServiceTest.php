<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SecretBox;
use App\Security\Totp;
use App\Security\TwoFactorService;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;

final class TwoFactorServiceTest extends DatabaseTestCase
{
    private const NOW = 1_700_000_000;
    private const KEY = 'test-app-key-test-app-key-test-app-key-test-app-key-1234567890abcd';

    private Totp $totp;
    private TwoFactorService $svc;

    protected function dirtyTables(): array
    {
        return ['user_recovery_codes', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new Totp();
        $this->svc = new TwoFactorService($this->pdo, $this->totp, new SecretBox(self::KEY), self::KEY, 'ADWOL PIA');
    }

    private function codeFor(string $secret, int $stepOffset = 0): string
    {
        return $this->totp->codeAt($secret, $this->totp->stepAt(self::NOW) + $stepOffset);
    }

    /** @return array{user:array{id:int,uuid:string,email:string,password:string},secret:string,recovery:list<string>} */
    private function enrolled(): array
    {
        $user = $this->makeUser(['role' => 'admin']);
        $secret = $this->svc->newSecret();
        $recovery = $this->svc->enable($user['id'], $secret, $this->codeFor($secret), self::NOW);

        return ['user' => $user, 'secret' => $secret, 'recovery' => $recovery];
    }

    public function testEnrolmentNeedsAValidCodeStoresTheSeedEncryptedAndReturnsTenRecoveryCodes(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $secret = $this->svc->newSecret();

        try {
            $this->svc->enable($user['id'], $secret, '000000', self::NOW);
            self::fail('a wrong code must not enrol');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertFalse($this->svc->isEnabled($user['id']));

        $codes = $this->svc->enable($user['id'], $secret, $this->codeFor($secret), self::NOW);

        self::assertTrue($this->svc->isEnabled($user['id']));
        self::assertCount(10, $codes);
        self::assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codes[0]);
        $stored = (string) $this->pdo->query("SELECT totp_secret FROM users WHERE id = {$user['id']}")->fetchColumn();
        self::assertStringNotContainsString($secret, $stored, 'the seed is not stored in the clear');
        self::assertSame(10, $this->svc->remainingRecoveryCodes($user['id']));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM user_recovery_codes WHERE code_hash LIKE '%" . str_replace('-', '', $codes[0]) . "%'")->fetchColumn(), 'codes are stored hashed');
    }

    public function testALoginCodeWorksOnceAndCannotBeReplayed(): void
    {
        $e = $this->enrolled();
        // Enrolment consumed the current step; the next step's code is the first valid login code.
        $code = $this->codeFor($e['secret'], 1);

        self::assertTrue($this->svc->verifyLogin($e['user']['id'], $code, self::NOW));
        self::assertFalse($this->svc->verifyLogin($e['user']['id'], $code, self::NOW), 'replay of the same code');
        self::assertFalse($this->svc->verifyLogin($e['user']['id'], $this->codeFor($e['secret'], 0), self::NOW), 'an already-consumed earlier step');
        self::assertFalse($this->svc->verifyLogin($e['user']['id'], '', self::NOW));
        self::assertFalse($this->svc->verifyLogin($e['user']['id'], '123456', self::NOW));
    }

    public function testRecoveryCodesAreSingleUseCaseAndDashInsensitive(): void
    {
        $e = $this->enrolled();
        $code = $e['recovery'][0];

        self::assertTrue($this->svc->verifyLogin($e['user']['id'], strtolower($code), self::NOW), 'lower-case with the dash');
        self::assertFalse($this->svc->verifyLogin($e['user']['id'], $code, self::NOW), 'already used');
        self::assertTrue($this->svc->verifyLogin($e['user']['id'], str_replace('-', ' ', $e['recovery'][1]), self::NOW));
        self::assertSame(8, $this->svc->remainingRecoveryCodes($e['user']['id']));
        self::assertFalse($this->svc->verifyLogin($e['user']['id'], 'AAAAA-AAAAA', self::NOW), 'never issued');
    }

    public function testCodesAreBoundToTheirOwnUser(): void
    {
        $a = $this->enrolled();
        $b = $this->enrolled();

        self::assertFalse($this->svc->verifyLogin($b['user']['id'], $a['recovery'][0], self::NOW), 'A\'s recovery code on B');
        self::assertFalse($this->svc->verifyLogin($b['user']['id'], $this->codeFor($a['secret'], 1), self::NOW), 'A\'s TOTP on B');
    }

    public function testRegeneratingInvalidatesTheOldSetAndDisableClearsEverything(): void
    {
        $e = $this->enrolled();
        $old = $e['recovery'][0];

        $new = $this->svc->regenerateRecoveryCodes($e['user']['id']);
        self::assertCount(10, $new);
        self::assertFalse($this->svc->verifyLogin($e['user']['id'], $old, self::NOW));
        self::assertTrue($this->svc->verifyLogin($e['user']['id'], $new[0], self::NOW));

        $this->svc->disable($e['user']['id']);
        self::assertFalse($this->svc->isEnabled($e['user']['id']));
        self::assertSame(0, $this->svc->remainingRecoveryCodes($e['user']['id']));
        self::assertFalse($this->svc->verifyLogin($e['user']['id'], $this->codeFor($e['secret'], 2), self::NOW), 'a disabled account accepts no 2FA code');
    }

    public function testAnUnreadableSeedFailsClosed(): void
    {
        $e = $this->enrolled();
        $other = new TwoFactorService($this->pdo, $this->totp, new SecretBox('a-completely-different-app-key-a-completely-different-app-key-00'), 'x', 'ADWOL PIA');

        self::assertFalse($other->verifyLogin($e['user']['id'], $this->codeFor($e['secret'], 1), self::NOW));
    }
}
