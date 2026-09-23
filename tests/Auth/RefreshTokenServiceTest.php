<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\InvalidTokenException;
use App\Auth\ReauthRequiredException;
use App\Auth\RefreshTokenService;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;

final class RefreshTokenServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'users'];
    }

    private function service(int $reauthDays = 7): RefreshTokenService
    {
        return new RefreshTokenService($this->pdo, $reauthDays);
    }

    public function testIssueThenRotateGivesAWorkingNewTokenAndBurnsTheOld(): void
    {
        $user = $this->makeUser();
        $svc  = $this->service();

        $first = $svc->issueForLogin($user['id'], new DateTimeImmutable(), 'device-1');
        $rotated = $svc->rotate($first['token'], 'device-1');

        self::assertSame($user['id'], $rotated['user_id']);
        self::assertNotSame($first['token'], $rotated['token']);

        // The new token rotates again fine...
        $again = $svc->rotate($rotated['token'], 'device-1');
        self::assertNotEmpty($again['token']);

        // ...but the very first token is now dead.
        $this->expectException(InvalidTokenException::class);
        $svc->rotate($first['token'], 'device-1');
    }

    public function testReusingAnOldTokenRevokesTheWholeFamily(): void
    {
        $user = $this->makeUser();
        $svc  = $this->service();

        $t1 = $svc->issueForLogin($user['id'], new DateTimeImmutable(), null)['token'];
        $t2 = $svc->rotate($t1, null)['token'];

        // Replay the already-used t1 -> theft signal.
        try {
            $svc->rotate($t1, null);
            self::fail('expected InvalidTokenException');
        } catch (InvalidTokenException) {
            // expected
        }

        // t2 (the legitimate current token) must now also be rejected.
        $this->expectException(InvalidTokenException::class);
        $svc->rotate($t2, null);
    }

    public function testPastTheSevenDayCapRequiresReauth(): void
    {
        $user = $this->makeUser();
        $svc  = $this->service(7);

        // Login anchored 8 days ago.
        $loginAt = (new DateTimeImmutable())->modify('-8 days');
        $token = $svc->issueForLogin($user['id'], $loginAt, null)['token'];

        $this->expectException(ReauthRequiredException::class);
        $svc->rotate($token, null);
    }

    public function testRevokedFamilyCannotRotate(): void
    {
        $user = $this->makeUser();
        $svc  = $this->service();

        $issued = $svc->issueForLogin($user['id'], new DateTimeImmutable(), null);
        $svc->revokeFamily($issued['family_id']);

        $this->expectException(InvalidTokenException::class);
        $svc->rotate($issued['token'], null);
    }

    public function testRawTokenIsNeverStored(): void
    {
        $user = $this->makeUser();
        $issued = $this->service()->issueForLogin($user['id'], new DateTimeImmutable(), null);

        $stored = $this->pdo->query('SELECT token_hash FROM refresh_tokens')->fetchAll();

        self::assertCount(1, $stored);
        self::assertNotSame($issued['token'], $stored[0]['token_hash']);
        self::assertSame(hash('sha256', $issued['token']), $stored[0]['token_hash']);
    }
}
