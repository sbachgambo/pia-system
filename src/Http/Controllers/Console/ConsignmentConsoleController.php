<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Documents\TradeDetailsRepository;
use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Office\ClientService;
use App\Office\ConsignmentRepository;
use App\Office\ConsignmentService;
use App\Office\NotFoundException;
use App\Records\RecordAttachmentService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/nxp — browser CRUD over ConsignmentService. Phase 6 adds
 * the (optional) trade/banking detail form that feeds the CCI.
 */
final class ConsignmentConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly ConsignmentService $consignments,
        private readonly ClientService $clients,
        private readonly ConsignmentRepository $consignmentRepo,
        private readonly TradeDetailsRepository $tradeDetails,
        private readonly RecordAttachmentService $attachments,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = [
            'direction'   => $q['direction'] ?? null,
            'zone'        => $q['zone'] ?? null,
            'client_uuid' => $q['client_uuid'] ?? null,
            'q'           => $q['q'] ?? null,
            'missing_nxp' => !empty($q['missing_nxp']) ? '1' : null,
        ];
        $list = $this->consignments->list(Pagination::fromRequest($request), $filters);

        return $this->render($request, $response, 'console/consignments/index', [
            'list'    => $list,
            'filters' => $filters,
        ]);
    }

    public function new(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'console/consignments/form', [
            'mode'        => 'create',
            'consignment' => null,
            'clients'     => $this->clientOptions(),
            'old'         => [],
            'errors'      => [],
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        try {
            $consignment = $this->consignments->create(Input::fromRequest($request));
        } catch (ApiException $e) {
            return $this->render($request, $response, 'console/consignments/form', [
                'mode'        => 'create',
                'consignment' => null,
                'clients'     => $this->clientOptions(),
                'old'         => $body,
                'errors'      => $this->errorsFrom($e),
            ], status: 422);
        }

        $this->flash($request, 'success', 'NXP record created.');

        return $this->redirect($response, '/console/nxp/' . $consignment['uuid'] . '/edit');
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $consignment = $this->consignments->get((string) $args['uuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'NXP record not found.');
            return $this->redirect($response, '/console/nxp');
        }

        $consId = $this->consignmentRepo->findIdByUuid((string) $args['uuid']);
        $trade = $consId !== null ? $this->tradeDetails->forConsignment($consId) : null;

        return $this->render($request, $response, 'console/consignments/form', [
            'mode'        => 'edit',
            'consignment' => $consignment,
            'clients'     => $this->clientOptions(),
            'old'         => $consignment,
            'errors'      => [],
            'trade'       => $trade ?? [],
            'attachments' => $this->attachments->list('consignment', $consignment['uuid']),
            'history'     => $consId !== null ? $this->consignmentRepo->history($consId) : [],
        ]);
    }

    /** Trade/banking fields that feed the CCI (Phase 6) — saved independently of the main form. */
    public function saveTradeDetails(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $id = $this->consignmentRepo->findIdByUuid($uuid);

        if ($id === null) {
            $this->flash($request, 'error', 'NXP record not found.');
            return $this->redirect($response, '/console/nxp');
        }

        $body = (array) $request->getParsedBody();
        $blank = static fn ($v) => $v === null || trim((string) $v) === '' ? null : trim((string) $v);

        $this->tradeDetails->upsert($id, [
            'importer_name'      => $blank($body['importer_name'] ?? null),
            'importer_address'   => $blank($body['importer_address'] ?? null),
            'nepc_number'        => $blank($body['nepc_number'] ?? null),
            'exporter_bank_name' => $blank($body['exporter_bank_name'] ?? null),
            'importer_bank_name' => $blank($body['importer_bank_name'] ?? null),
            'bank_reference'     => $blank($body['bank_reference'] ?? null),
            'invoice_number'     => $blank($body['invoice_number'] ?? null),
            'invoice_date'       => $blank($body['invoice_date'] ?? null),
            'basis_of_sale'      => $blank($body['basis_of_sale'] ?? null),
            'method_of_payment'  => $blank($body['method_of_payment'] ?? null),
            'freight_charges'    => $blank($body['freight_charges'] ?? null),
            'insurance_charges'  => $blank($body['insurance_charges'] ?? null),
        ]);

        $this->flash($request, 'success', 'Trade / banking details saved.');

        return $this->redirect($response, '/console/nxp/' . $uuid . '/edit');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $body = (array) $request->getParsedBody();

        try {
            $consignment = $this->consignments->update($uuid, Input::fromRequest($request));
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'NXP record not found.');
            return $this->redirect($response, '/console/nxp');
        } catch (ApiException $e) {
            return $this->render($request, $response, 'console/consignments/form', [
                'mode'        => 'edit',
                'consignment' => ['uuid' => $uuid] + $body,
                'clients'     => $this->clientOptions(),
                'old'         => $body,
                'errors'      => $this->errorsFrom($e),
            ], status: 422);
        }

        $this->flash($request, 'success', 'NXP record updated.');

        return $this->redirect($response, '/console/nxp/' . $consignment['uuid'] . '/edit');
    }

    /**
     * Clients for the <select>. Capped at 200 — if a deployment outgrows that
     * this becomes a typeahead (follow-up).
     *
     * @return list<array<string,mixed>>
     */
    private function clientOptions(): array
    {
        return $this->clients->list(new Pagination(1, 200), [])['data'];
    }

    /** @return array<string,string> */
    private function errorsFrom(ApiException $e): array
    {
        $field = $e->getDetails()['field'] ?? '_';

        return [(string) $field => $e->getMessage()];
    }
}
