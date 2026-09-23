<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inspections;

use App\Auth\UserRepository;
use App\Documents\DocumentService;
use App\Http\Support\ClientContext;
use App\Http\Support\CurrentUser;
use App\Http\Support\Input;
use App\Http\Support\Json;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Inspections\ReviewService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Office review of synced inspections (brief §5: amend / finalize; reject
 * added, DEV-10). JWT + office roles.
 *
 *   GET  /api/office/inspections            queue
 *   GET  /api/inspections/{uuid}            detail (findings + attachments + audit)
 *   POST /api/inspections/{uuid}/amend
 *   POST /api/inspections/{uuid}/finalize
 *   POST /api/inspections/{uuid}/reject
 */
final class ReviewController
{
    public function __construct(
        private readonly ReviewService $review,
        private readonly UserRepository $users,
        private readonly DocumentService $documents,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function queue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);

        return Json::write(
            $response,
            $this->review->queue(Pagination::fromRequest($request), ['status' => $q['status'] ?? null]),
        );
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->review->show((string) $args['uuid']));
    }

    public function amend(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->review->amend(
            (string) $args['uuid'],
            Input::fromRequest($request),
            CurrentUser::id($request, $this->users),
            ClientContext::ip($request),
        ));
    }

    /**
     * Finalizing locks the inspection (D1) and, per D2, should produce the
     * CCI. Document generation runs as a best-effort follow-up: a PDF/storage
     * fault must not undo — or even fail — a finalize that already committed.
     * If it fails, `document` is null and `document_generation_error` carries
     * a machine code the office can retry against
     * (`POST /api/inspections/{uuid}/documents`).
     */
    public function finalize(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uuid = (string) $args['uuid'];
        $actorId = CurrentUser::id($request, $this->users);

        $result = $this->review->finalize($uuid, $actorId, ClientContext::ip($request));

        try {
            $result['document'] = $this->documents->generateForInspection($uuid, $actorId, 'CCI');
        } catch (Throwable $e) {
            $result['document'] = null;
            $result['document_generation_error'] = $e instanceof ApiException ? $e->getErrorCode() : 'generation_failed';
            $this->logger->error('CCI generation failed after finalize', [
                'inspection_uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return Json::write($response, $result);
    }

    public function reject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, $this->review->reject(
            (string) $args['uuid'],
            Input::fromRequest($request),
            CurrentUser::id($request, $this->users),
            ClientContext::ip($request),
        ));
    }
}
