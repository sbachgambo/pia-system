<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance;

use App\Compliance\ComplianceService;
use App\Http\Support\Json;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Compliance tracking (brief §4, Phase 7). Admin/super_admin only — this is
 * inspector performance data, not routine office work.
 *
 *   GET  /api/compliance            list (filters: alert_only, inspector_uuid, period_month)
 *   POST /api/compliance/evaluate   (re)run the evaluator for a month (default: current)
 */
final class ComplianceController
{
    public function __construct(private readonly ComplianceService $compliance)
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = [
            'alert_only'     => isset($q['alert_only']) && $q['alert_only'] === '1',
            'inspector_uuid' => $q['inspector_uuid'] ?? null,
            'period_month'   => $q['period_month'] ?? null,
        ];

        return Json::write($response, $this->compliance->list(Pagination::fromRequest($request), $filters));
    }

    /** Body is optional — `{"month": "YYYY-MM"}`, or nothing to evaluate the current month. */
    public function evaluate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $month = is_array($body) && is_string($body['month'] ?? null) ? trim($body['month']) : null;

        return Json::write($response, ['data' => $this->compliance->evaluate($month)], 200);
    }
}
