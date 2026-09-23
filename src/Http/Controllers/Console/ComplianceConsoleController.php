<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Compliance\ComplianceService;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/compliance — admin-only (gated at the route level by
 * ConsoleRoleMiddleware, see AppFactory). The 3-strikes tracking (Phase 7).
 */
final class ComplianceConsoleController extends AbstractConsoleController
{
    public function __construct(Renderer $view, private readonly ComplianceService $compliance)
    {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = ['alert_only' => ($q['alert_only'] ?? '') === '1'];
        $list = $this->compliance->list(Pagination::fromRequest($request), $filters);

        return $this->render($request, $response, 'console/compliance/index', [
            'list' => $list, 'filters' => $filters,
        ]);
    }

    public function evaluate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $month = trim((string) ($body['month'] ?? '')) ?: null;

        $results = $this->compliance->evaluate($month);
        $alerts = array_filter($results, static fn (array $r): bool => $r['alert_triggered']);

        $this->flash(
            $request,
            count($alerts) > 0 ? 'error' : 'success',
            sprintf('Evaluated %d inspector(s), %d alert(s).', count($results), count($alerts)),
        );

        return $this->redirect($response, '/console/compliance');
    }
}
