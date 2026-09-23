<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The front controller must boot end to end and the health probe must report
 * a live database.
 */
final class HealthEndpointTest extends TestCase
{
    public function testHealthReturns200WithDatabaseOk(): void
    {
        $app = AppFactory::create();

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/health');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertNotEmpty($response->getHeaderLine('X-Request-Id'), 'RequestIdMiddleware should stamp every response.');

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('ok', $body['status']);
        self::assertSame('testing', $body['env']);
        self::assertSame('ok', $body['checks']['database']['status']);
    }

    public function testUnknownRouteReturnsJsonErrorEnvelope(): void
    {
        $app = AppFactory::create();

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/does-not-exist');
        $response = $app->handle($request);

        self::assertSame(404, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('not_found', $body['error']['code']);
        // RequestId middleware is outermost, so even a routing failure carries
        // a correlatable id in both the body and the header.
        self::assertNotEmpty($body['error']['request_id']);
        self::assertSame($response->getHeaderLine('X-Request-Id'), $body['error']['request_id']);
    }

    public function testRootRouteReturnsOk(): void
    {
        $app = AppFactory::create();

        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('href="/console/login"', $html);
        self::assertStringContainsString('href="/app/"', $html);
    }

    public function testInboundRequestIdIsEchoedBack(): void
    {
        $app = AppFactory::create();

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/health')
            ->withHeader('X-Request-Id', 'pwa-sync-abc123');

        $response = $app->handle($request);

        self::assertSame('pwa-sync-abc123', $response->getHeaderLine('X-Request-Id'));
    }
}
