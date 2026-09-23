<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Export\ExportRepository;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Returns\CsvWriter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Export CSV" for the console list screens. clients / consignments /
 * inspections are open to every office role (same visibility as the list
 * screens); users is admin-only via the route group. Every export is written
 * to the audit log — a bulk data pull is exactly the kind of event an admin
 * later wants to be able to see.
 */
final class ExportConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly ExportRepository $exports,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function clients(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = ['type' => $q['type'] ?? null, 'q' => $q['q'] ?? null];

        return $this->csv($request, $response, 'clients', $filters, $this->exports->clients($filters));
    }

    public function consignments(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = [
            'direction'   => $q['direction'] ?? null,
            'zone'        => $q['zone'] ?? null,
            'client_uuid' => $q['client_uuid'] ?? null,
            'q'           => $q['q'] ?? null,
        ];

        return $this->csv($request, $response, 'nxp-records', $filters, $this->exports->consignments($filters));
    }

    public function inspections(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $filters = ['status' => Query::params($request)['status'] ?? null];

        return $this->csv($request, $response, 'inspections', $filters, $this->exports->inspections($filters));
    }

    public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = ['role' => $q['role'] ?? null, 'status' => $q['status'] ?? null, 'q' => $q['q'] ?? null];

        return $this->csv($request, $response, 'users', $filters, $this->exports->users($filters));
    }

    /**
     * @param array<string,mixed> $filters
     * @param array{headers:list<string>, rows:list<list<string>>} $data
     */
    private function csv(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $name,
        array $filters,
        array $data,
    ): ResponseInterface {
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        $this->audit->record(
            isset($actor['id']) ? (int) $actor['id'] : null,
            'export.' . $name,
            'export',
            0,
            null,
            ['filters' => array_filter($filters, static fn ($v) => $v !== null && $v !== ''), 'rows' => count($data['rows'])],
            ClientContext::ip($request),
        );

        $response->getBody()->write(CsvWriter::writeSafe($data['headers'], $data['rows']));

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '-' . gmdate('Ymd-His') . '.csv"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
