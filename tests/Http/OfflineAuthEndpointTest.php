<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Phase 8a — the offline-auth contract the PWA's `auth_cache` is built from:
 * the `offline` block on login / refresh / me, and the POST/DELETE
 * `/api/me/pin` lifecycle.
 */
final class OfflineAuthEndpointTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'rate_limits', 'users'];
    }

    private function req(string $method, string $path, ?array $json = null, ?string $token = null): ResponseInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($token !== null) {
            $r = $r->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')
                   ->withBody((new StreamFactory())->createStream((string) json_encode($json)));
        }

        return AppFactory::create()->handle($r);
    }

    private function decode(ResponseInterface $r): array
    {
        return json_decode((string) $r->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{token:string, refresh:string} */
    private function login(string $email, string $password): array
    {
        $bundle = $this->decode($this->req('POST', '/api/auth/login', ['email' => $email, 'password' => $password]));

        return ['token' => $bundle['access_token'], 'refresh' => $bundle['refresh_token'], 'bundle' => $bundle];
    }

    public function testLoginBundleCarriesTheOfflineBlock(): void
    {
        $u = $this->makeUser(['email' => 'off1@adwol.test', 'password' => 'Field-Pass-1', 'role' => 'inspector']);

        $bundle = $this->login('off1@adwol.test', 'Field-Pass-1')['bundle'];

        self::assertArrayHasKey('offline', $bundle);
        self::assertFalse($bundle['offline']['pin_set']);
        self::assertNull($bundle['offline']['pin_hash']);
        self::assertNotNull($bundle['offline']['reauth_deadline']);

        $deadline = new \DateTimeImmutable($bundle['offline']['reauth_deadline']);
        $expected = (new \DateTimeImmutable())->modify('+7 days');
        self::assertLessThan(120, abs($deadline->getTimestamp() - $expected->getTimestamp()));
    }

    public function testPinLifecycle(): void
    {
        $this->makeUser(['email' => 'off2@adwol.test', 'password' => 'Field-Pass-1', 'role' => 'inspector']);
        $session = $this->login('off2@adwol.test', 'Field-Pass-1');

        // wrong password -> 401, PIN not set
        $bad = $this->req('POST', '/api/me/pin', ['current_password' => 'nope', 'pin' => '8261'], $session['token']);
        self::assertSame(401, $bad->getStatusCode());

        // weak PIN -> 422 weak_pin
        $weak = $this->req('POST', '/api/me/pin', ['current_password' => 'Field-Pass-1', 'pin' => '1234'], $session['token']);
        self::assertSame(422, $weak->getStatusCode());
        self::assertSame('weak_pin', $this->decode($weak)['error']['code']);

        // good PIN -> 200, offline block now has a usable hash
        $ok = $this->req('POST', '/api/me/pin', ['current_password' => 'Field-Pass-1', 'pin' => '8261'], $session['token']);
        self::assertSame(200, $ok->getStatusCode());
        $offline = $this->decode($ok)['offline'];
        self::assertTrue($offline['pin_set']);
        self::assertStringStartsWith('$argon2id$', $offline['pin_hash']);
        self::assertTrue(password_verify('8261', $offline['pin_hash']));

        // /api/me reflects it
        $me = $this->decode($this->req('GET', '/api/me', null, $session['token']));
        self::assertTrue($me['offline']['pin_set']);
        self::assertSame($offline['pin_hash'], $me['offline']['pin_hash']);

        // refresh bundle carries it too
        $refreshed = $this->decode($this->req('POST', '/api/auth/refresh', ['refresh_token' => $session['refresh']]));
        self::assertTrue($refreshed['offline']['pin_set']);
        self::assertStringStartsWith('$argon2id$', $refreshed['offline']['pin_hash']);

        // DELETE clears it
        $cleared = $this->decode($this->req('DELETE', '/api/me/pin', null, $session['token']));
        self::assertFalse($cleared['offline']['pin_set']);
        self::assertNull($cleared['offline']['pin_hash']);
        self::assertNull($this->decode($this->req('GET', '/api/me', null, $session['token']))['offline']['pin_hash']);
    }

    public function testPinEndpointNeedsAuth(): void
    {
        self::assertSame(401, $this->req('POST', '/api/me/pin', ['current_password' => 'x', 'pin' => '8261'])->getStatusCode());
    }
}
