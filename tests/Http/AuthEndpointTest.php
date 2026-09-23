<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * End-to-end HTTP tests for the auth endpoints (brief §5).
 */
final class AuthEndpointTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'rate_limits', 'users'];
    }

    private function request(string $method, string $path, ?array $json = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($json !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream((string) json_encode($json)));
        }

        return AppFactory::create()->handle($request);
    }

    /** @return array<string,mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testLoginHappyPathReturnsBundle(): void
    {
        $user = $this->makeUser(['email' => 'api@adwol.test', 'password' => 'Login-Pass-1', 'role' => 'office_reviewer']);

        $res = $this->request('POST', '/api/auth/login', ['email' => 'api@adwol.test', 'password' => 'Login-Pass-1']);

        self::assertSame(200, $res->getStatusCode());
        $body = $this->decode($res);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertSame($user['uuid'], $body['user']['uuid']);
        self::assertSame('office_reviewer', $body['user']['role']);
    }

    public function testLoginWithBadPasswordIs401(): void
    {
        $this->makeUser(['email' => 'api2@adwol.test', 'password' => 'Login-Pass-1']);

        $res = $this->request('POST', '/api/auth/login', ['email' => 'api2@adwol.test', 'password' => 'WRONG']);

        self::assertSame(401, $res->getStatusCode());
        self::assertSame('invalid_credentials', $this->decode($res)['error']['code']);
    }

    public function testLoginWithMissingFieldsIs422(): void
    {
        $res = $this->request('POST', '/api/auth/login', ['email' => 'x@y.z']);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame('validation_failed', $this->decode($res)['error']['code']);
    }

    public function testProtectedRouteRequiresToken(): void
    {
        $res = $this->request('GET', '/api/me');

        self::assertSame(401, $res->getStatusCode());
        self::assertSame('invalid_token', $this->decode($res)['error']['code']);
    }

    public function testProtectedRouteAcceptsTokenFromLogin(): void
    {
        $this->makeUser(['email' => 'me@adwol.test', 'password' => 'Login-Pass-1', 'role' => 'admin', 'zone' => 'Kano']);

        $login = $this->decode($this->request('POST', '/api/auth/login', [
            'email' => 'me@adwol.test', 'password' => 'Login-Pass-1',
        ]));

        $res = $this->request('GET', '/api/me', null, ['Authorization' => 'Bearer ' . $login['access_token']]);

        self::assertSame(200, $res->getStatusCode());
        $body = $this->decode($res);
        self::assertSame('me@adwol.test', $body['email']);
        self::assertSame('admin', $body['role']);
        self::assertSame('Kano', $body['zone']);
        self::assertNotNull($body['last_login_at']);
    }

    public function testRefreshRotatesAndOldTokenStopsWorking(): void
    {
        $this->makeUser(['email' => 'rot@adwol.test', 'password' => 'Login-Pass-1']);

        $login = $this->decode($this->request('POST', '/api/auth/login', [
            'email' => 'rot@adwol.test', 'password' => 'Login-Pass-1',
        ]));

        $refreshed = $this->decode($this->request('POST', '/api/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ]));
        self::assertNotSame($login['refresh_token'], $refreshed['refresh_token']);

        // Re-using the original refresh token now fails (family revoked).
        $replay = $this->request('POST', '/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        self::assertSame(401, $replay->getStatusCode());
        self::assertSame('invalid_token', $this->decode($replay)['error']['code']);

        // And the rotated one is dead too.
        $replay2 = $this->request('POST', '/api/auth/refresh', ['refresh_token' => $refreshed['refresh_token']]);
        self::assertSame(401, $replay2->getStatusCode());
    }

    public function testAuthRoutesAreRateLimited(): void
    {
        // .env.testing: AUTH_RATE_LIMIT_MAX = 10
        $lastStatus = null;
        for ($i = 1; $i <= 11; $i++) {
            $lastStatus = $this->request('POST', '/api/auth/login', ['email' => 'z@z.z', 'password' => 'x'])
                ->getStatusCode();
        }

        self::assertSame(429, $lastStatus, 'the 11th attempt in the window should be rate limited');
    }
}
