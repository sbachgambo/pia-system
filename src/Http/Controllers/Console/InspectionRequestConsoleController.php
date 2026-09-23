<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Inspections\InspectionRepository;
use App\Inspections\SchedulingService;
use App\Office\ConsignmentService;
use App\Office\InspectionRequestService;
use App\Office\NotFoundException;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/inspection-requests — browser CRUD + status transitions over
 * InspectionRequestService, and assigning an inspector (SchedulingService),
 * which creates the inspection the inspector's device then picks up.
 */
final class InspectionRequestConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly InspectionRequestService $requests,
        private readonly ConsignmentService $consignments,
        private readonly SchedulingService $scheduling,
        private readonly InspectionRepository $inspections,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = [
            'status'  => $q['status'] ?? null,
            'overdue' => isset($q['overdue']) && $q['overdue'] !== '' && $q['overdue'] !== '0',
        ];
        $list = $this->requests->list(Pagination::fromRequest($request), $filters);

        return $this->render($request, $response, 'console/inspection_requests/index', [
            'list'    => $list,
            'filters' => $filters,
        ]);
    }

    public function new(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // ?consignment=<uuid> preselects the NXP record (the "Create a request" link on its page).
        $preselect = Query::params($request)['consignment'] ?? null;

        return $this->render($request, $response, 'console/inspection_requests/form', [
            'consignments' => $this->consignmentOptions(),
            'old'          => is_string($preselect) ? ['consignment_uuid' => $preselect] : [],
            'errors'       => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        try {
            $ir = $this->requests->create(Input::fromRequest($request), (int) $user['id']);
        } catch (ApiException $e) {
            return $this->render($request, $response, 'console/inspection_requests/form', [
                'consignments' => $this->consignmentOptions(),
                'old'          => $body,
                'errors'       => $this->errorsFrom($e),
            ], status: 422);
        }

        $this->flash($request, 'success', 'Inspection request created.');

        return $this->redirect($response, '/console/inspection-requests/' . $ir['uuid']);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $ir = $this->requests->get((string) $args['uuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection request not found.');
            return $this->redirect($response, '/console/inspection-requests');
        }

        return $this->render($request, $response, 'console/inspection_requests/show', [
            'ir'          => $ir,
            'inspections' => $this->inspections->forRequest((string) $ir['uuid']),
            'inspectors'  => $this->inspections->activeInspectors(),
        ]);
    }

    /** Assign an inspector: creates the inspection the inspector's app downloads. */
    public function schedule(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $body = (array) $request->getParsedBody();
        $fields = [
            'inspector_uuid'  => (string) ($body['inspector_uuid'] ?? ''),
            'location_type'   => (string) ($body['location_type'] ?? ''),
            'location_detail' => trim((string) ($body['location_detail'] ?? '')),
        ];
        // <input type="datetime-local"> sends "2026-10-01T09:00", read as UTC like the rest of the console.
        if (trim((string) ($body['scheduled_at'] ?? '')) !== '') {
            $fields['scheduled_at'] = trim((string) $body['scheduled_at']);
        }

        try {
            $this->scheduling->schedule($uuid, Input::fromArray($fields));
            $this->flash($request, 'success', 'Inspector assigned. The inspection appears on their app at the next sync.');
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection request not found.');

            return $this->redirect($response, '/console/inspection-requests');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect($response, '/console/inspection-requests/' . $uuid);
    }

    public function transition(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];

        try {
            $this->requests->transition($uuid, Input::fromRequest($request));
            $this->flash($request, 'success', 'Status updated.');
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection request not found.');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect($response, '/console/inspection-requests/' . $uuid);
    }

    public function reschedule(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];

        try {
            $this->requests->reschedule($uuid, Input::fromRequest($request));
            $this->flash($request, 'success', 'Rescheduled.');
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection request not found.');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect($response, '/console/inspection-requests/' . $uuid);
    }

    /** @return list<array<string,mixed>> */
    private function consignmentOptions(): array
    {
        return $this->consignments->list(new Pagination(1, 200), [])['data'];
    }

    /** @return array<string,string> */
    private function errorsFrom(ApiException $e): array
    {
        $field = $e->getDetails()['field'] ?? '_';

        return [(string) $field => $e->getMessage()];
    }
}
