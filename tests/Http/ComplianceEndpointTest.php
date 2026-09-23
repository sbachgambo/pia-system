<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AppFactory;
use App\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class ComplianceEndpointTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['compliance_tracking', 'refresh_tokens', 'rate_limits', 'inspections', 'inspection_requests',
                'consignments', 'clients', 'users'];
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

    private function tokenFor(string $role): array
    {
        $u = $this->makeUser(['role' => $role, 'password' => 'Comp-Pass-1']);
        $res = $this->req('POST', '/api/auth/login', ['email' => $u['email'], 'password' => 'Comp-Pass-1']);

        return ['token' => $this->decode($res)['access_token'], 'uuid' => $u['uuid'], 'id' => $u['id']];
    }

    public function testEvaluateAndListRoundTrip(): void
    {
        $admin = $this->tokenFor('admin');
        $inspector = $this->tokenFor('inspector');
        $office = $this->tokenFor('office_reviewer');

        // Evaluate *last* calendar month explicitly — unambiguously past the
        // container's default 24h grace regardless of what time "now" is,
        // and the boundary-timing itself is already covered by
        // ComplianceEvaluatorTest. Day 15 avoids month-end overflow.
        $lastMonth = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('first day of last month')->setTime(12, 0, 0);
        $scheduledAt = $lastMonth->modify('+14 days');

        $client = $this->makeClientRow();
        $cons = $this->makeConsignmentRow($client['id']);
        $req = $this->makeInspectionRequestRow($cons['id'], $office['id']);
        $this->makeScheduledInspectionRow($req['id'], $inspector['id'], [
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            'status' => 'scheduled',
        ]);

        $evaluated = $this->decode($this->req('POST', '/api/compliance/evaluate', ['month' => $lastMonth->format('Y-m')], $admin['token']));
        self::assertCount(1, $evaluated['data']);
        self::assertSame($inspector['uuid'], $evaluated['data'][0]['inspector_uuid']);
        self::assertSame(1, $evaluated['data'][0]['missed_windows_count']);

        $list = $this->decode($this->req('GET', '/api/compliance', null, $admin['token']));
        self::assertSame(1, $list['pagination']['total']);

        $alertsOnly = $this->decode($this->req('GET', '/api/compliance?alert_only=1', null, $admin['token']));
        self::assertSame(0, $alertsOnly['pagination']['total']); // one miss isn't 3 strikes yet
    }

    public function testRoleGatesAdminOnly(): void
    {
        $office = $this->tokenFor('office_reviewer');
        $inspector = $this->tokenFor('inspector');

        self::assertSame(403, $this->req('GET', '/api/compliance', null, $office['token'])->getStatusCode());
        self::assertSame(403, $this->req('GET', '/api/compliance', null, $inspector['token'])->getStatusCode());
        self::assertSame(403, $this->req('POST', '/api/compliance/evaluate', [], $office['token'])->getStatusCode());
        self::assertSame(401, $this->req('GET', '/api/compliance')->getStatusCode());
    }
}
