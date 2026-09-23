<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/audit-log — admin/super_admin only. A system-wide browse over the
 * append-only audit_log table; per-entity history already exists on the
 * inspection review page, this is the "who changed what, anywhere" view that
 * was missing.
 */
final class AuditLogConsoleController extends AbstractConsoleController
{
    public function __construct(Renderer $view, private readonly AuditLog $auditLog)
    {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = [
            'action'      => $q['action'] ?? null,
            'entity_type' => $q['entity_type'] ?? null,
        ];
        $page = Pagination::fromRequest($request, default: 50);
        $result = $this->auditLog->paginate($page->perPage, $page->offset(), $filters);

        return $this->render($request, $response, 'console/audit_log/index', [
            'list'    => $page->envelope($result['rows'], $result['total']),
            'filters' => $filters,
            'actions' => $this->auditLog->distinctActions(),
        ]);
    }
}
