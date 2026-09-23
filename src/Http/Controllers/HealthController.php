<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Support\Json;
use App\Support\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /health — unauthenticated liveness/readiness probe.
 *
 * Returns 200 when the app booted and the database answers, 503 otherwise.
 * Intentionally minimal: no schema/version details that would help an attacker
 * fingerprint the deploy.
 */
final class HealthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $appEnv,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = Database::ping($this->pdo);

        $payload = [
            'status'  => $db['ok'] ? 'ok' : 'degraded',
            'service' => 'pia-api',
            'env'     => $this->appEnv,
            'time'    => gmdate('c'),
            'checks'  => [
                'database' => $db['ok']
                    ? ['status' => 'ok', 'latency_ms' => $db['latency_ms'] ?? null]
                    : ['status' => 'fail'],
            ],
        ];

        return Json::write($response, $payload, $db['ok'] ? 200 : 503);
    }
}
