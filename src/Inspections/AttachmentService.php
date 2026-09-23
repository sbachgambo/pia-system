<?php

declare(strict_types=1);

namespace App\Inspections;

use App\Office\NotFoundException;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Upload and retrieval of inspection attachment binaries.
 *
 * Upload: the attachment must already have been declared in an inspection
 * sync (a stub row exists, keyed by client_uuid) and must belong to the
 * uploading inspector; the inspection must not be locked (D1). The bytes are
 * checksum- and type-checked by AttachmentStorage.
 *
 * Retrieval (GET /api/attachments/{id}, DEV-3): the assigned inspector or any
 * office reviewer/admin.
 */
final class AttachmentService
{
    private const OFFICE_ROLES = ['office_reviewer', 'admin', 'super_admin'];
    private const LOCKED = ['finalized', 'rejected'];

    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly AttachmentStorage $storage,
        private readonly SyncLogRepository $syncLog,
    ) {
    }

    /**
     * @return array<string,mixed> the stored attachment metadata
     */
    public function upload(
        string $clientUuid,
        int $inspectorId,
        string $bytes,
        string $declaredSha256,
        ?string $originalName,
        ?string $deviceId,
    ): array {
        $row = $this->attachments->findByClientUuid(strtolower($clientUuid));

        if ($row === null) {
            // The device must sync the inspection (declaring its attachments) first.
            throw new ApiException(409, 'attachment_not_declared', 'This attachment was not declared in a prior sync.');
        }
        if ((int) $row['inspector_id'] !== $inspectorId) {
            throw new NotFoundException('Attachment');
        }
        if (in_array($row['inspection_status'], self::LOCKED, true)) {
            throw new ApiException(409, 'inspection_locked', 'The inspection is finalised; attachments are locked.');
        }

        $meta = $this->storage->put($clientUuid, $bytes, $declaredSha256);

        $this->attachments->markUploaded(
            (int) $row['id'],
            $meta['relative_path'],
            $meta['mime_type'],
            $meta['byte_size'],
            $meta['checksum_sha256'],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $this->syncLog->record($clientUuid, 'attachment', $inspectorId, $deviceId, 'success');

        return [
            'client_uuid'     => strtolower($clientUuid),
            'file_type'       => (string) $row['file_type'],
            'mime_type'       => $meta['mime_type'],
            'byte_size'       => $meta['byte_size'],
            'checksum_sha256' => $meta['checksum_sha256'],
            'uploaded_at'     => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    /**
     * @param array{uuid:string,role:string} $requester  from the access token
     * @param int $requesterId  the requester's user id
     * @return array{bytes:string, mime_type:string, filename:string}
     */
    public function download(int $attachmentId, string $requesterRole, int $requesterId): array
    {
        $row = $this->attachments->findById($attachmentId) ?? throw new NotFoundException('Attachment');

        $allowed = in_array($requesterRole, self::OFFICE_ROLES, true)
            || (int) $row['inspector_id'] === $requesterId;

        if (!$allowed) {
            throw new NotFoundException('Attachment'); // don't reveal existence
        }
        if ($row['file_path'] === null) {
            throw new ApiException(409, 'attachment_pending', 'The binary for this attachment has not been uploaded yet.');
        }

        $file = $this->storage->read((string) $row['file_path']);
        if ($file === null) {
            throw new ApiException(410, 'attachment_gone', 'The stored file is missing.');
        }

        $ext = match ($file['mime_type']) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/heic' => 'heic', 'application/pdf' => 'pdf', default => 'bin',
        };

        return [
            'bytes'     => $file['bytes'],
            'mime_type' => $file['mime_type'],
            'filename'  => ($row['original_name'] ?: ('attachment-' . $attachmentId)) . '.' . $ext,
        ];
    }
}
