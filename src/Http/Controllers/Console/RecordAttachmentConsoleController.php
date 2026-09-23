<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\View\Renderer;
use App\Office\NotFoundException;
use App\Records\RecordAttachmentService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Upload / download / delete of supporting documents on clients and
 * consignments. Every office role may upload and download; deleting is limited
 * to the uploader or an admin. Uploads and deletions are audit-logged against
 * the client/consignment they belong to.
 */
final class RecordAttachmentConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly RecordAttachmentService $attachments,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function uploadForClient(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->upload($request, $response, 'client', (string) $args['uuid'], '/console/clients/%s/edit');
    }

    public function uploadForConsignment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->upload($request, $response, 'consignment', (string) $args['uuid'], '/console/nxp/%s/edit');
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $file = $this->attachments->download((string) $args['uuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Document not found.');
            return $this->redirect($response, '/console');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect($response, '/console');
        }

        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', $file['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . addslashes($file['filename']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        try {
            $row = $this->attachments->get((string) $args['uuid']);
            $isAdmin = in_array($actor['role'] ?? null, ['admin', 'super_admin'], true);

            if (!$isAdmin && (int) $row['uploaded_by'] !== (int) ($actor['id'] ?? 0)) {
                $this->flash($request, 'error', 'Only the uploader or an admin can delete this document.');

                return $this->redirect($response, '/console');
            }

            $this->attachments->delete((string) $args['uuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Document not found.');
            return $this->redirect($response, '/console');
        }

        $this->audit->record(
            isset($actor['id']) ? (int) $actor['id'] : null,
            'attachment.delete',
            (string) $row['entity_type'],
            (int) $row['entity_id'],
            ['name' => $row['original_name'], 'size' => (int) $row['byte_size'], 'sha256' => $row['checksum_sha256']],
            null,
            ClientContext::ip($request),
        );

        $this->flash($request, 'success', 'Document deleted.');

        return $this->redirect($response, $this->backTo($request, $row));
    }

    private function upload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $type,
        string $uuid,
        string $backPattern,
    ): ResponseInterface {
        $back = sprintf($backPattern, $uuid);
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $file = $request->getUploadedFiles()['file'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->flash($request, 'error', 'Choose a file to upload (PDF, JPEG, PNG or WebP).');
            return $this->redirect($response, $back);
        }

        try {
            $row = $this->attachments->add($type, $uuid, (string) $file->getStream(), $file->getClientFilename(), (int) ($actor['id'] ?? 0));
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'The record was not found.');
            return $this->redirect($response, '/console');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect($response, $back);
        }

        $this->audit->record(
            isset($actor['id']) ? (int) $actor['id'] : null,
            'attachment.upload',
            $type,
            (int) $row['entity_id'],
            null,
            ['name' => $row['original_name'], 'size' => (int) $row['byte_size'], 'sha256' => $row['checksum_sha256']],
            ClientContext::ip($request),
        );

        $this->flash($request, 'success', 'Document uploaded.');

        return $this->redirect($response, $back);
    }

    /**
     * Where to send the user after a delete: the edit page of the owning
     * record. The delete route only knows the attachment, so the owner's uuid
     * comes back from the form's own referring field.
     *
     * @param array<string,mixed> $row
     */
    private function backTo(ServerRequestInterface $request, array $row): string
    {
        $body = (array) $request->getParsedBody();
        $ret = is_string($body['return'] ?? null) ? $body['return'] : '';

        // Only ever bounce to a console edit page (no open redirect).
        return preg_match('#^/console/(clients|nxp)/[0-9a-f-]{36}/edit$#', $ret) === 1 ? $ret : '/console';
    }
}
