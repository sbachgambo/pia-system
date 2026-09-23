<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Middleware\BoardReadOnlyMiddleware;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\Support\SessionStore;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Board of Directors accounts can look at everything and change nothing — and
 * the rule is one gate, so it covers write actions nobody has written yet.
 */
final class BoardReadOnlyMiddlewareTest extends TestCase
{
    private function attempt(string $role, string $method, string $path, string $referer = ''): array
    {
        $session = new SessionStore();
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(ConsoleAuthMiddleware::ATTRIBUTE, ['id' => 1, 'role' => $role])
            ->withAttribute(SessionMiddleware::ATTRIBUTE, $session);
        if ($referer !== '') {
            $request = $request->withHeader('Referer', $referer);
        }

        $handler = new class implements RequestHandlerInterface {
            public bool $reached = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached = true;

                return (new ResponseFactory())->createResponse(200);
            }
        };

        $response = (new BoardReadOnlyMiddleware())->process($request, $handler);

        return [$response, $handler->reached, $session];
    }

    public function testTheBoardCanReadEverything(): void
    {
        foreach (['/console', '/console/income', '/console/nxp/abc/edit', '/console/invoices', '/console/archive/download'] as $path) {
            [$response, $reached] = $this->attempt('board', 'GET', $path);
            self::assertTrue($reached, $path);
            self::assertSame(200, $response->getStatusCode());
        }
    }

    public function testTheBoardCannotWriteAnything(): void
    {
        foreach (['/console/nxp', '/console/clients/x', '/console/inspections/x/finalize', '/console/invoices', '/console/some/future/action'] as $path) {
            [$response, $reached, $session] = $this->attempt('board', 'POST', $path, 'http://127.0.0.1/console/nxp?x=1');
            self::assertFalse($reached, $path);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/console/nxp', $response->getHeaderLine('Location'), 'sent back to the page they came from');
            self::assertStringContainsString('read-only', $session->get('_flash')[0]['message']);
        }
    }

    public function testNewRecordFormsAreRefusedAndARefererOffSiteIsIgnored(): void
    {
        [$response, $reached] = $this->attempt('board', 'GET', '/console/clients/new', 'https://evil.example/phish');

        self::assertFalse($reached);
        self::assertSame('/console', $response->getHeaderLine('Location'));
    }

    public function testTheBoardCanStillManageTheirOwnAccountAndSignOut(): void
    {
        foreach (['/console/account/password', '/console/account/two-factor/start', '/console/logout'] as $path) {
            [, $reached] = $this->attempt('board', 'POST', $path);
            self::assertTrue($reached, $path);
        }
    }

    public function testOtherRolesAreUntouched(): void
    {
        foreach (['admin', 'super_admin', 'office_reviewer'] as $role) {
            [, $reached] = $this->attempt($role, 'POST', '/console/nxp');
            self::assertTrue($reached, $role);
        }
    }
}
