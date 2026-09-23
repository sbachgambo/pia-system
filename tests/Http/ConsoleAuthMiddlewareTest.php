<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Auth\UserRepository;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Support\SessionStore;
use App\Security\TwoFactorPolicy;
use App\Settings\OperationalSettingsRepository;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ConsoleAuthMiddlewareTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['refresh_tokens', 'operational_settings', 'users'];
    }

    private function middleware(): ConsoleAuthMiddleware
    {
        return new ConsoleAuthMiddleware(
            new UserRepository($this->pdo),
            new TwoFactorPolicy(new OperationalSettingsRepository($this->pdo, ['compliance_grace_hours' => 24])),
        );
    }

    private function handlerReturning(int $status): RequestHandlerInterface
    {
        return new class ($status) implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function __construct(private readonly int $status)
            {
            }

            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->seen = $request;
                return (new ResponseFactory())->createResponse($this->status);
            }
        };
    }

    private function request(string $path, SessionStore $session): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withAttribute(SessionMiddleware::ATTRIBUTE, $session);
    }

    public function testRedirectsToLoginWhenNoSessionUser(): void
    {
        $session = new SessionStore();
        $response = $this->middleware()->process($this->request('/console/clients', $session), $this->handlerReturning(200));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/console/login', $response->getHeaderLine('Location'));
        // remembers where the user was headed
        self::assertSame('/console/clients', $session->get(ConsoleAuthMiddleware::INTENDED_KEY));
    }

    public function testRedirectsWhenSessionUserNoLongerExists(): void
    {
        $session = new SessionStore();
        $session->set(ConsoleAuthMiddleware::SESSION_KEY, '00000000-0000-0000-0000-000000000000');

        $response = $this->middleware()->process($this->request('/console', $session), $this->handlerReturning(200));

        self::assertSame(302, $response->getStatusCode());
        self::assertFalse($session->has(ConsoleAuthMiddleware::SESSION_KEY));
    }

    public function testRedirectsWhenUserIsInspector(): void
    {
        $u = $this->makeUser(['role' => 'inspector']);
        $session = new SessionStore();
        $session->set(ConsoleAuthMiddleware::SESSION_KEY, $u['uuid']);

        $response = $this->middleware()->process($this->request('/console', $session), $this->handlerReturning(200));

        self::assertSame(302, $response->getStatusCode());
    }

    public function testPassesAndAttachesUserWhenValidOfficeRole(): void
    {
        $u = $this->makeUser(['role' => 'office_reviewer']);
        $session = new SessionStore();
        $session->set(ConsoleAuthMiddleware::SESSION_KEY, $u['uuid']);

        $handler = $this->handlerReturning(200);
        $response = $this->middleware()->process($this->request('/console/clients', $session), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($handler->seen?->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE));
        self::assertSame($u['uuid'], $handler->seen->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE)['uuid']);
    }
    public function testASessionOlderThanSessionsValidAfterIsRejectedAndANewerOneIsNot(): void
    {
        $u = $this->makeUser(['role' => 'office_reviewer']);
        $this->pdo->exec("UPDATE users SET sessions_valid_after = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id = {$u['id']}");

        $old = new SessionStore();
        $old->set(ConsoleAuthMiddleware::SESSION_KEY, $u['uuid']);
        $old->set(ConsoleAuthMiddleware::LOGIN_AT_KEY, time() - 7200);
        self::assertSame(302, $this->middleware()->process($this->request('/console', $old), $this->handlerReturning(200))->getStatusCode());
        self::assertFalse($old->has(ConsoleAuthMiddleware::SESSION_KEY));

        $fresh = new SessionStore();
        $fresh->set(ConsoleAuthMiddleware::SESSION_KEY, $u['uuid']);
        $fresh->set(ConsoleAuthMiddleware::LOGIN_AT_KEY, time());
        self::assertSame(200, $this->middleware()->process($this->request('/console', $fresh), $this->handlerReturning(200))->getStatusCode());
    }

    public function testMandatoryTwoFactorConfinesUnenrolledAdminsToTheAccountPage(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $reviewer = $this->makeUser(['role' => 'office_reviewer']);
        $sessionFor = function (array $u): SessionStore {
            $s = new SessionStore();
            $s->set(ConsoleAuthMiddleware::SESSION_KEY, $u['uuid']);

            return $s;
        };

        // Policy off: nobody is confined.
        self::assertSame(200, $this->middleware()->process($this->request('/console/clients', $sessionFor($admin)), $this->handlerReturning(200))->getStatusCode());

        (new OperationalSettingsRepository($this->pdo, ['compliance_grace_hours' => 24]))->setRequireTwoFactor(true, null);

        $s = $sessionFor($admin);
        $blocked = $this->middleware()->process($this->request('/console/clients', $s), $this->handlerReturning(200));
        self::assertSame(302, $blocked->getStatusCode());
        self::assertSame('/console/account', $blocked->getHeaderLine('Location'));
        self::assertTrue($s->has(ConsoleAuthMiddleware::SESSION_KEY), 'confined, not signed out');

        self::assertSame(200, $this->middleware()->process($this->request('/console/account/two-factor/setup', $sessionFor($admin)), $this->handlerReturning(200))->getStatusCode(), 'the account pages stay reachable');
        self::assertSame(200, $this->middleware()->process($this->request('/console/clients', $sessionFor($reviewer)), $this->handlerReturning(200))->getStatusCode(), 'the requirement is for admin roles only');

        $this->pdo->exec("UPDATE users SET totp_enabled_at = UTC_TIMESTAMP(), totp_secret = 'x' WHERE id = {$admin['id']}");
        self::assertSame(200, $this->middleware()->process($this->request('/console/clients', $sessionFor($admin)), $this->handlerReturning(200))->getStatusCode(), 'an enrolled admin is not confined');
    }
}
