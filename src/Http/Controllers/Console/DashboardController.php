<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Dashboard\DashboardMetrics;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Income\IncomeReport;
use App\Office\ClientService;
use App\Office\ConsignmentService;
use App\Office\InspectionRequestService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console — landing page: a few counts and quick links.
 */
final class DashboardController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly ClientService $clients,
        private readonly ConsignmentService $consignments,
        private readonly InspectionRequestService $requests,
        private readonly DashboardMetrics $metrics,
        private readonly AuditLog $audit,
        private readonly IncomeReport $income,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $one = new Pagination(1, 1);

        $stats = [
            'clients'      => $this->clients->list($one, [])['pagination']['total'],
            'consignments' => $this->consignments->list($one, [])['pagination']['total'],
            'pending'      => $this->requests->list($one, ['status' => 'pending'])['pagination']['total'],
            'scheduled'    => $this->requests->list($one, ['status' => 'scheduled'])['pagination']['total'],
            'overdue'      => $this->requests->list($one, ['overdue' => true])['pagination']['total'],
        ];

        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $isAdmin = in_array($user['role'] ?? null, ['admin', 'super_admin'], true);
        // Money and oversight panels: admins and the Board of Directors.
        $oversight = $isAdmin || ($user['role'] ?? null) === 'board';

        return $this->render($request, $response, 'console/dashboard', [
            'stats'    => $stats,
            'monthly'  => $this->metrics->monthlyInspections(6),
            'statuses' => $this->metrics->requestStatusCounts(),
            'alerts'   => $oversight ? $this->metrics->complianceAlerts() : null,
            'activity' => $oversight ? $this->audit->paginate(8, 0)['rows'] : [],
            'income'   => $oversight ? $this->incomeCard($request) : null,
            'readOnly' => ($user['role'] ?? null) === 'board',
        ]);
    }

    /** @return array<string,mixed> the income card's data, for the period in ?income= */
    private function incomeCard(ServerRequestInterface $request): array
    {
        $q = Query::params($request);
        $error = null;
        try {
            $period = IncomeReport::period((string) ($q['income'] ?? 'this_month'), $q['from'] ?? null, $q['to'] ?? null);
        } catch (ApiException $e) {
            $period = IncomeReport::period('this_month');
            $error = $e->getMessage();
        }

        return [
            'period'  => $period,
            'periods' => IncomeReport::PERIODS,
            'report'  => $this->income->build($period),
            'monthly' => $this->income->monthly(12),
            'error'   => $error,
        ];
    }
}
