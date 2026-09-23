<?php

declare(strict_types=1);

namespace App\Http\Controllers\Office;

use App\Auth\UserRepository;
use App\Http\Support\CurrentUser;
use App\Http\Support\Input;
use App\Http\Support\Json;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Office\InspectionRequestService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /api/office/inspection-requests — office CRUD + status transitions
 * (JWT + office roles).
 */
final class InspectionRequestController
{
    public function __construct(
        private readonly InspectionRequestService $requests,
        private readonly UserRepository $users,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = CurrentUser::id($request, $this->users);

        return Json::write(
            $response,
            $this->requests->create(Input::fromRequest($request), $userId),
            201,
        );
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->requests->get((string) $args['uuid']));
    }

    public function reschedule(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->requests->reschedule((string) $args['uuid'], Input::fromRequest($request)));
    }

    public function transition(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->requests->transition((string) $args['uuid'], Input::fromRequest($request)));
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = [
            'status'           => $q['status'] ?? null,
            'consignment_uuid' => $q['consignment_uuid'] ?? null,
            'overdue'          => isset($q['overdue']) && $q['overdue'] !== '' && $q['overdue'] !== '0',
        ];

        return Json::write($response, $this->requests->list(Pagination::fromRequest($request), $filters));
    }
}
