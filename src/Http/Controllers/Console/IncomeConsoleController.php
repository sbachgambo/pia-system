<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Income\IncomeReport;
use App\Returns\CsvWriter;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/income — the service-fee income report, plus the dashboard card's
 * fragment (the card swaps periods without a page reload by fetching `card`).
 *
 * Admins and the Board of Directors only, via the route group.
 */
final class IncomeConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly IncomeReport $income,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$period, $error] = $this->period($request);

        return $this->render($request, $response, 'console/income/index', [
            'period'  => $period,
            'periods' => IncomeReport::PERIODS,
            'report'  => $this->income->build($period),
            'error'   => $error,
        ], $error !== null ? 422 : 200);
    }

    /** The dashboard card alone, for a period switch without a reload. */
    public function card(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->cardHtml($request));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$period] = $this->period($request);
        $data = IncomeReport::csvRows($this->income->build($period));

        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $this->audit->record(
            isset($actor['id']) ? (int) $actor['id'] : null,
            'export.income',
            'export',
            0,
            null,
            ['from' => $period['from_date'], 'to' => $period['to_date'], 'rows' => count($data['rows']) - 1],
            ClientContext::ip($request),
        );

        $response->getBody()->write(CsvWriter::writeSafe($data['headers'], $data['rows']));

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="income-' . $period['from_date'] . '-to-' . $period['to_date'] . '.csv"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function cardHtml(ServerRequestInterface $request): string
    {
        [$period, $error] = $this->period($request, 'income');

        return $this->view->renderRaw('console/income/_card', [
            'period'  => $period,
            'periods' => IncomeReport::PERIODS,
            'report'  => $this->income->build($period),
            'monthly' => $this->income->monthly(12),
            'error'   => $error,
        ]);
    }

    /**
     * The period from the query string; a bad custom range falls back to this
     * month with the reason, rather than an error page.
     *
     * @return array{0:array<string,mixed>,1:?string}
     */
    private function period(ServerRequestInterface $request, string $param = 'period'): array
    {
        $q = Query::params($request);
        $key = (string) ($q[$param] ?? $q['period'] ?? 'this_month');

        try {
            return [IncomeReport::period($key, $q['from'] ?? null, $q['to'] ?? null), null];
        } catch (ApiException $e) {
            return [IncomeReport::period('this_month'), $e->getMessage()];
        }
    }
}
