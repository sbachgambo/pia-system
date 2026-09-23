<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Auth\UserRepository;
use App\Documents\DocumentService;
use App\Http\Support\CurrentUser;
use App\Http\Support\Input;
use App\Http\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Document generation + retrieval (Phase 6, D2/D7).
 *
 *   POST /api/inspections/{uuid}/documents        generate (DEV-13, additive)
 *   GET  /api/inspections/{uuid}/documents         list for an inspection (DEV-13)
 *   GET  /api/documents/{uuid}                     download (brief §5)
 *
 * All office-role gated (JWT + RoleMiddleware) — trade-finance data, same
 * sensitivity as the office console.
 */
final class DocumentController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly UserRepository $users,
    ) {
    }

    public function generate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Validate against the schema's full type set; DocumentService is the
        // single source of truth for which of those actually have a renderer
        // today (CRF/IDR -> a clear `unsupported_document_type` 422, not a
        // generic validation error).
        $input = Input::fromRequest($request);
        $type = $input->has('type') ? $input->requiredEnum('type', ['CCI', 'NNCI', 'CRF', 'IDR']) : 'CCI';

        $doc = $this->documents->generateForInspection(
            (string) $args['uuid'],
            CurrentUser::id($request, $this->users),
            $type,
        );

        return Json::write($response, $doc, 201);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::write($response, ['data' => $this->documents->listForInspection((string) $args['uuid'])]);
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->documents->download((string) $args['uuid']);

        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . addslashes($file['filename']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus(200);
    }
}
