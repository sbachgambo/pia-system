<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Http\Support\Pagination;
use App\Support\ApiException;
use DateTimeImmutable;

/** Thin service layer over ComplianceRepository/ComplianceEvaluator for controllers. */
final class ComplianceService
{
    public function __construct(
        private readonly ComplianceRepository $tracking,
        private readonly ComplianceEvaluator $evaluator,
    ) {
    }

    /**
     * @param array{alert_only?:bool, inspector_uuid?:string, period_month?:string} $filters
     * @return array<string,mixed>
     */
    public function list(Pagination $page, array $filters): array
    {
        $repoFilters = [
            'alert_triggered' => !empty($filters['alert_only']) ? true : null,
            'inspector_uuid'  => $filters['inspector_uuid'] ?? null,
            'period_month'    => $filters['period_month'] ?? null,
        ];
        $result = $this->tracking->paginate($page->perPage, $page->offset(), $repoFilters);

        return $page->envelope(array_map([Representation::class, 'tracking'], $result['rows']), $result['total']);
    }

    /**
     * Evaluate a month (default: the current one). `$month` is "YYYY-MM" if
     * given.
     *
     * @return list<array<string,mixed>>
     */
    public function evaluate(?string $month): array
    {
        $target = $this->parseMonth($month);
        $rows = $this->evaluator->evaluateMonth($target);

        return array_map([Representation::class, 'tracking'], $rows);
    }

    private function parseMonth(?string $month): DateTimeImmutable
    {
        if ($month === null || $month === '') {
            return new DateTimeImmutable('now');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new ApiException(422, 'validation_failed', '`month` must be YYYY-MM.', ['field' => 'month']);
        }

        try {
            return new DateTimeImmutable($month . '-01');
        } catch (\Exception) {
            throw new ApiException(422, 'validation_failed', '`month` is not a valid month.', ['field' => 'month']);
        }
    }
}
