<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Documents\Fees;
use App\Documents\DocumentService;
use App\Documents\ShipmentDetailsRepository;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Inspections\InspectionRepository;
use App\Inspections\ReviewService;
use App\Office\NotFoundException;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * /console/inspections — the office review queue and the amend / finalise /
 * reject actions, over ReviewService. Sits inside the console auth gate
 * (office roles only). Phase 6 adds the shipment/NESS detail form (feeds the
 * CCI) and manual document generation.
 */
final class ReviewConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly ReviewService $review,
        private readonly InspectionRepository $inspections,
        private readonly ShipmentDetailsRepository $shipmentDetails,
        private readonly DocumentService $documents,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $filters = ['status' => $q['status'] ?? null];
        $list = $this->review->queue(Pagination::fromRequest($request), $filters);

        return $this->render($request, $response, 'console/inspections/index', [
            'list'    => $list,
            'filters' => $filters,
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];

        try {
            $ins = $this->review->show($uuid);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection not found.');
            return $this->redirect($response, '/console/inspections');
        }

        $row = $this->inspections->findByUuid($uuid);
        $shipment = $row !== null ? $this->shipmentDetails->forInspection((int) $row['id']) : null;
        $documents = $this->documents->listForInspection($uuid);

        // What the CCI will print in box 50 if no NESS figure is recorded.
        $rate = isset($shipment['exchange_rate']) && $shipment['exchange_rate'] !== null ? (float) $shipment['exchange_rate'] : null;
        $nessSuggestion = $row !== null ? [
            'amount'   => Fees::ness((float) $row['declared_value'], $rate),
            'currency' => $rate !== null ? 'NGN' : (string) $row['currency'],
            'rate'     => Fees::percent(Fees::NESS_RATE),
        ] : null;

        return $this->render($request, $response, 'console/inspections/show', [
            'ins' => $ins, 'errors' => [], 'shipment' => $shipment ?? [], 'documents' => $documents,
            'nessSuggestion' => $nessSuggestion,
        ]);
    }

    /** Shipment / NESS fields that feed the CCI (Phase 6). Office-only, any time before finalize. */
    public function saveShipmentDetails(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $row = $this->inspections->findByUuid($uuid) ?? null;

        if ($row === null) {
            $this->flash($request, 'error', 'Inspection not found.');
            return $this->redirect($response, '/console/inspections');
        }

        $body = (array) $request->getParsedBody();
        $blank = static fn ($v) => $v === null || trim((string) $v) === '' ? null : trim((string) $v);

        $this->shipmentDetails->upsert((int) $row['id'], [
            'shipment_date'           => $blank($body['shipment_date'] ?? null),
            'shipping_agent'          => $blank($body['shipping_agent'] ?? null),
            'carrier_vessel'          => $blank($body['carrier_vessel'] ?? null),
            'loading_ref_no'          => $blank($body['loading_ref_no'] ?? null),
            'container_numbers'       => $blank($body['container_numbers'] ?? null),
            'packing_details'         => $blank($body['packing_details'] ?? null),
            'quality_remark'          => $blank($body['quality_remark'] ?? null),
            'gross_weight_kg'         => $blank($body['gross_weight_kg'] ?? null),
            'net_weight_kg'           => $blank($body['net_weight_kg'] ?? null),
            'forex_exchange_date'     => $blank($body['forex_exchange_date'] ?? null),
            'exchange_rate'           => $blank($body['exchange_rate'] ?? null),
            'ness_charges_paid'       => $blank($body['ness_charges_paid'] ?? null),
            'ness_receipt_no'         => $blank($body['ness_receipt_no'] ?? null),
            'ness_actual_payable'     => $blank($body['ness_actual_payable'] ?? null),
            'ness_balance_paid'       => $blank($body['ness_balance_paid'] ?? null),
            'ness_balance_receipt_no' => $blank($body['ness_balance_receipt_no'] ?? null),
        ]);

        $this->flash($request, 'success', 'Shipment / NESS details saved.');

        return $this->redirect($response, '/console/inspections/' . $uuid);
    }

    /** Manual (re)generate a document — also the retry path if finalize's auto-generation failed. */
    public function generateDocument(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $body = (array) $request->getParsedBody();
        $type = in_array($body['type'] ?? null, ['CCI', 'NNCI'], true) ? $body['type'] : 'CCI';

        try {
            $doc = $this->documents->generateForInspection($uuid, $this->actorId($request), $type);
            $this->flash($request, 'success', "{$type} {$doc['document_number']} generated.");
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection not found.');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        } catch (Throwable $e) {
            $this->flash($request, 'error', 'Document generation failed: ' . $e->getMessage());
        }

        return $this->redirect($response, '/console/inspections/' . $uuid);
    }

    public function amend(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $body = (array) $request->getParsedBody();

        $payload = [];
        if (($body['location_type'] ?? '') !== '') {
            $payload['location_type'] = $body['location_type'];
        }
        if (($body['location_detail'] ?? '') !== '') {
            $payload['location_detail'] = $body['location_detail'];
        }
        // Findings rows: keep only those with a checklist_item filled in.
        if (isset($body['findings']) && is_array($body['findings'])) {
            $rows = array_values(array_filter(
                $body['findings'],
                static fn ($r): bool => is_array($r) && trim((string) ($r['checklist_item'] ?? '')) !== '',
            ));
            $payload['findings'] = $rows;
        }

        try {
            $this->review->amend($uuid, Input::fromArray($payload), $this->actorId($request), ClientContext::ip($request));
            $this->flash($request, 'success', 'Inspection amended.');
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection not found.');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect($response, '/console/inspections/' . $uuid);
    }

    /**
     * Finalizing locks the inspection (D1) and, per D2, should produce the
     * CCI. Mirrors ReviewController::finalize (the API path) — document
     * generation is a best-effort follow-up so a PDF/storage fault never
     * undoes an already-committed finalize; the office can retry from this
     * same page's "Generate CCI" button either way.
     */
    public function finalize(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];

        try {
            $this->review->finalize($uuid, $this->actorId($request), ClientContext::ip($request));
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection not found.');
            return $this->redirect($response, '/console/inspections/' . $uuid);
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect($response, '/console/inspections/' . $uuid);
        }

        try {
            $doc = $this->documents->generateForInspection($uuid, $this->actorId($request), 'CCI');
            $this->flash($request, 'success', "Inspection finalised and locked. CCI {$doc['document_number']} generated.");
        } catch (Throwable $e) {
            $this->flash($request, 'success', 'Inspection finalised and locked.');
            $this->flash($request, 'error', 'CCI generation failed — use "Generate CCI" below to retry: ' . $e->getMessage());
        }

        return $this->redirect($response, '/console/inspections/' . $uuid);
    }

    public function reject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $body = (array) $request->getParsedBody();

        try {
            $this->review->reject($uuid, Input::fromArray(['reason' => $body['reason'] ?? '']), $this->actorId($request), ClientContext::ip($request));
            $this->flash($request, 'success', 'Inspection rejected and returned to the inspector.');
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Inspection not found.');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect($response, '/console/inspections/' . $uuid);
    }

    /** Console-side download (cookie session, not the JWT api route). */
    public function downloadDocument(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $file = $this->documents->download((string) $args['docUuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Document not found.');
            return $this->redirect($response, '/console/inspections/' . (string) $args['uuid']);
        }

        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . addslashes($file['filename']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus(200);
    }

    private function actorId(ServerRequestInterface $request): int
    {
        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        return (int) $user['id'];
    }
}
