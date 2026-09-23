<?php

declare(strict_types=1);

namespace App\Records;

use App\Inspections\AttachmentStorage;
use App\Office\ClientRepository;
use App\Office\ConsignmentRepository;
use App\Office\NotFoundException;
use App\Support\ApiException;
use Ramsey\Uuid\Uuid;

/**
 * Supporting documents on clients and consignments. Validation (size, real
 * content type sniffed from the bytes, checksum) is AttachmentStorage's job —
 * the same hardened storage the inspector photo path uses, pointed at a
 * separate directory. Files are only ever served back through an authenticated
 * console route.
 */
final class RecordAttachmentService
{
    public const TYPES = ['client', 'consignment'];

    public function __construct(
        private readonly RecordAttachmentRepository $repo,
        private readonly AttachmentStorage $storage,
        private readonly ClientRepository $clients,
        private readonly ConsignmentRepository $consignments,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(string $type, string $entityUuid): array
    {
        return $this->repo->forEntity($type, $this->entityId($type, $entityUuid));
    }

    /** @return array<string,mixed> the stored row (includes internal ids; do not expose raw) */
    public function add(string $type, string $entityUuid, string $bytes, ?string $originalName, int $uploadedBy): array
    {
        $entityId = $this->entityId($type, $entityUuid);
        $uuid = Uuid::uuid4()->toString();

        $meta = $this->storage->put($uuid, $bytes, hash('sha256', $bytes));

        try {
            return $this->repo->create($uuid, [
                'entity_type'     => $type,
                'entity_id'       => $entityId,
                'original_name'   => self::cleanName($originalName, $meta['mime_type']),
                'file_path'       => $meta['relative_path'],
                'mime_type'       => $meta['mime_type'],
                'byte_size'       => $meta['byte_size'],
                'checksum_sha256' => $meta['checksum_sha256'],
                'uploaded_by'     => $uploadedBy,
            ]);
        } catch (\Throwable $e) {
            $this->storage->delete($meta['relative_path']); // don't orphan the file if the row fails
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function get(string $uuid): array
    {
        return $this->repo->findByUuid($uuid) ?? throw new NotFoundException('Document');
    }

    /** @return array{bytes:string,mime_type:string,filename:string} */
    public function download(string $uuid): array
    {
        $row = $this->get($uuid);
        $file = $this->storage->read((string) $row['file_path']);

        if ($file === null) {
            throw new ApiException(410, 'attachment_gone', 'The stored file is missing.');
        }

        return ['bytes' => $file['bytes'], 'mime_type' => $file['mime_type'], 'filename' => (string) $row['original_name']];
    }

    /** @return array<string,mixed> the deleted row, for the audit entry */
    public function delete(string $uuid): array
    {
        $row = $this->get($uuid);
        $this->repo->delete((int) $row['id']);
        $this->storage->delete((string) $row['file_path']);

        return $row;
    }

    private function entityId(string $type, string $uuid): int
    {
        return match ($type) {
            'client'      => $this->clients->findIdByUuid($uuid) ?? throw new NotFoundException('Client'),
            'consignment' => $this->consignments->findIdByUuid($uuid) ?? throw new NotFoundException('NXP record'),
            default       => throw new ApiException(422, 'validation_failed', 'Unsupported record type.'),
        };
    }

    /** Strip any path, control characters and quotes; keep it short; make sure it has an extension. */
    public static function cleanName(?string $name, string $mime): string
    {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'application/pdf' => 'pdf'][$mime] ?? 'bin';
        $base = basename(str_replace('\\', '/', (string) $name));
        $base = (string) preg_replace('/[\x00-\x1F\x7F"\';<>|:*?]/u', '', $base);
        $base = trim(mb_substr($base, 0, 150));

        // The extension always comes from the sniffed content type, never from
        // the client-supplied name ("invoice.exe" that is really a PDF is
        // served as "invoice.pdf").
        $stem = trim(pathinfo($base, PATHINFO_FILENAME));

        return ($stem === '' || $stem === '.' ? 'document' : $stem) . '.' . $ext;
    }
}
