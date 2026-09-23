<?php

declare(strict_types=1);

namespace App\Http\Controllers\Returns;

use App\Auth\UserRepository;
use App\Http\Support\CurrentUser;
use App\Http\Support\Input;
use App\Http\Support\Json;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Returns\StatutoryReturnService;
use App\Support\ApiException;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Statutory returns (brief §4/§5/§8, D3). Admin/super_admin only.
 *
 *   POST /api/statutory-returns/generate      brief §5's exact path
 *   GET  /api/statutory-returns                list (DEV, additive)
 *   POST /api/statutory-returns/{uuid}/submit  mark submitted (DEV, additive — §4 has submitted_at/_by)
 *   GET  /api/statutory-returns/{uuid}/download
 */
final class StatutoryReturnController
{
    public function __construct(
        private readonly StatutoryReturnService $returns,
        private readonly UserRepository $users,
    ) {
    }

    public function generate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = Input::fromRequest($request);

        $doc = $this->returns->generate(
            $input->requiredEnum('agency', ['CBN', 'NEPC', 'NBS', 'MOF', 'CUSTOMS']),
            $this->requiredDate($input, 'period_start'),
            $this->requiredDate($input, 'period_end'),
            CurrentUser::id($request, $this->users),
        );

        return Json::write($response, $doc, 201);
    }

    private function requiredDate(Input $input, string $field): DateTimeImmutable
    {
        return $input->optionalDateTime($field)
            ?? throw new ApiException(422, 'validation_failed', "`{$field}` is required.", ['field' => $field]);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = ['agency' => $q['agency'] ?? null, 'status' => $q['status'] ?? null];

        return Json::write($response, $this->returns->list(Pagination::fromRequest($request), $filters));
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->returns->submit((string) $args['uuid'], CurrentUser::id($request, $this->users)));
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->returns->download((string) $args['uuid']);

        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . addslashes($file['filename']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus(200);
    }
}
