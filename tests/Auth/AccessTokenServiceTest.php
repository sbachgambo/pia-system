<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Auth\AccessTokenService;
use App\Auth\InvalidTokenException;
use PHPUnit\Framework\TestCase;

final class AccessTokenServiceTest extends TestCase
{
    private const SECRET = 'a-test-secret-that-is-definitely-long-enough-for-hs256-0123456789';

    private function service(int $ttl = 900, string $issuer = 'pia-api'): AccessTokenService
    {
        return new AccessTokenService(self::SECRET, $issuer, $ttl);
    }

    public function testIssueThenVerifyRoundTrip(): void
    {
        $issued = $this->service()->issue('user-uuid-123', 'office_reviewer', 'Lagos');

        self::assertArrayHasKey('token', $issued);
        self::assertSame(900, $issued['expires_in']);

        $claims = $this->service()->verify($issued['token']);

        self::assertSame('user-uuid-123', $claims['uuid']);
        self::assertSame('office_reviewer', $claims['role']);
        self::assertSame('Lagos', $claims['zone']);
        self::assertNotEmpty($claims['jti']);
    }

    public function testRejectsTamperedToken(): void
    {
        $token = $this->service()->issue('u', 'inspector', null)['token'];

        // Flip the last character of the payload/signature.
        $tampered = substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a');

        $this->expectException(InvalidTokenException::class);
        $this->service()->verify($tampered);
    }

    public function testRejectsWrongIssuer(): void
    {
        $token = $this->service(900, 'pia-api')->issue('u', 'inspector', null)['token'];

        $this->expectException(InvalidTokenException::class);
        $this->service(900, 'someone-else')->verify($token);
    }

    public function testRejectsExpiredToken(): void
    {
        // Negative TTL => already expired the moment it is issued.
        $token = $this->service(-10)->issue('u', 'inspector', null)['token'];

        $this->expectException(InvalidTokenException::class);
        $this->service()->verify($token);
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->service()->verify('not-a-jwt');
    }

    public function testRejectsTokenSignedWithDifferentSecret(): void
    {
        $other = new AccessTokenService('a-totally-different-secret-also-long-enough-for-hs256-987654321', 'pia-api', 900);
        $token = $other->issue('u', 'inspector', null)['token'];

        $this->expectException(InvalidTokenException::class);
        $this->service()->verify($token);
    }
}
