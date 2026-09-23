<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SecretBox;
use App\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** ASCII "12345678901234567890" — the shared secret in RFC 6238 Appendix B (SHA-1). */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testMatchesTheRfc6238PublishedVectors(): void
    {
        $totp = new Totp();

        foreach ([
            59          => '94287082',
            1111111109  => '07081804',
            1111111111  => '14050471',
            1234567890  => '89005924',
            2000000000  => '69279037',
            20000000000 => '65353130',
        ] as $time => $expected8) {
            self::assertSame($expected8, $totp->codeAt(self::RFC_SECRET, $totp->stepAt($time), 8), "T={$time}");
            self::assertSame(substr($expected8, -6), $totp->codeAt(self::RFC_SECRET, $totp->stepAt($time)), "6-digit T={$time}");
        }
    }

    public function testVerifyAcceptsTheAdjacentStepsButNotFurtherOut(): void
    {
        $totp = new Totp();
        $now = 1_700_000_000;
        $step = $totp->stepAt($now);

        foreach ([-1, 0, 1] as $off) {
            self::assertSame($step + $off, $totp->verify(self::RFC_SECRET, $totp->codeAt(self::RFC_SECRET, $step + $off), $now));
        }
        self::assertNull($totp->verify(self::RFC_SECRET, $totp->codeAt(self::RFC_SECRET, $step + 3), $now));
        self::assertNull($totp->verify(self::RFC_SECRET, $totp->codeAt(self::RFC_SECRET, $step - 3), $now));
    }

    public function testReplayProtectionRejectsAnyStepAtOrBeforeTheLastAccepted(): void
    {
        $totp = new Totp();
        $now = 1_700_000_000;
        $step = $totp->stepAt($now);
        $code = $totp->codeAt(self::RFC_SECRET, $step);

        self::assertSame($step, $totp->verify(self::RFC_SECRET, $code, $now));
        self::assertNull($totp->verify(self::RFC_SECRET, $code, $now, $step), 'the same code again');
        self::assertNull($totp->verify(self::RFC_SECRET, $totp->codeAt(self::RFC_SECRET, $step - 1), $now, $step), 'an earlier step');
        self::assertSame($step + 1, $totp->verify(self::RFC_SECRET, $totp->codeAt(self::RFC_SECRET, $step + 1), $now, $step), 'a later step is fine');
    }

    public function testMalformedCodesAreRejectedAndSpacesTolerated(): void
    {
        $totp = new Totp();
        $now = 1_700_000_000;
        $code = $totp->codeAt(self::RFC_SECRET, $totp->stepAt($now));

        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x', "{$code}9"] as $bad) {
            self::assertNull($totp->verify(self::RFC_SECRET, $bad, $now), $bad);
        }
        self::assertNotNull($totp->verify(self::RFC_SECRET, substr($code, 0, 3) . ' ' . substr($code, 3), $now));
    }

    public function testBase32RoundTripsAndRejectsGarbage(): void
    {
        foreach (['', 'a', 'ab', 'abc', "\x00\xff\x10binary", random_bytes(20)] as $bytes) {
            if ($bytes === '') {
                continue;
            }
            self::assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        }
        self::assertNull(Totp::base32Decode('not base32!'));
        self::assertNull(Totp::base32Decode(''));
        self::assertSame(32, strlen(Totp::generateSecret()));
        self::assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    public function testProvisioningUriCarriesTheParametersAuthenticatorsExpect(): void
    {
        $uri = Totp::provisioningUri('ABCDEF234567', 'admin@adwol.test', 'ADWOL PIA');

        self::assertStringStartsWith('otpauth://totp/ADWOL%20PIA:admin%40adwol.test?', $uri);
        self::assertStringContainsString('secret=ABCDEF234567', $uri);
        self::assertStringContainsString('issuer=ADWOL%20PIA', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function testSecretBoxEncryptsAuthenticatesAndIsKeyBound(): void
    {
        $box = new SecretBox(str_repeat('a', 64));
        $boxed = $box->encrypt(self::RFC_SECRET);

        self::assertStringNotContainsString(self::RFC_SECRET, $boxed);
        self::assertNotSame($boxed, $box->encrypt(self::RFC_SECRET), 'a fresh IV each time');
        self::assertSame(self::RFC_SECRET, $box->decrypt($boxed));
        self::assertNull((new SecretBox(str_repeat('b', 64)))->decrypt($boxed), 'another key cannot read it');

        $tampered = substr($boxed, 0, -2) . (substr($boxed, -2) === 'AA' ? 'BB' : 'AA');
        self::assertNull($box->decrypt($tampered), 'GCM detects tampering');
        self::assertNull($box->decrypt('garbage'));
        self::assertNull($box->decrypt('v1:!!!'));
    }
}
