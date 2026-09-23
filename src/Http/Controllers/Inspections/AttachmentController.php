<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inspections;

use App\Auth\AuthenticatedUser;
use App\Auth\UserRepository;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\CurrentUser;
use App\Http\Support\Json;
use App\Inspections\AttachmentService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/inspections/attachments/{clientUuid}  — upload a declared blob
 *   raw request body = the bytes; headers:
 *     X-Checksum-Sha256  (required)  the SHA-256 hex digest of the bytes
 *     X-Original-Name    (optional)
 *   JWT + `inspector` role.
 *
 * GET  /api/attachments/{id}  — download (brief §5). JWT; the assigned
 *   inspector or any office reviewer/admin (enforced in the service).
 */
final class AttachmentController
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly UserRepository $users,
    ) {
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $bytes = (string) $request->getBody();
        $sha   = $request->getHeaderLine('X-Checksum-Sha256');

        if ($sha === '') {
            throw new ApiException(422, 'validation_failed', 'X-Checksum-Sha256 header is required.', ['field' => 'checksum']);
        }

        $meta = $this->attachments->upload(
            (string) $args['clientUuid'],
            CurrentUser::id($request, $this->users),
            $bytes,
            $sha,
            $request->getHeaderLine('X-Original-Name') ?: null,
            ClientContext::deviceId($request),
        );

        return Json::write($response, $meta, 201);
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var AuthenticatedUser $auth */
        $auth = $request->getAttribute(JwtAuthMiddleware::ATTRIBUTE);
        $requesterId = CurrentUser::id($request, $this->users);

        $file = $this->attachments->download((int) $args['id'], $auth->role, $requesterId);

        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', $file['mime_type'])
            ->withHeader('Content-Disposition', 'inline; filename="' . addslashes($file['filename']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus(200);
    }
}
