<?php

declare(strict_types=1);

namespace App\Documents;

use App\Http\View\Renderer;
use App\Inspections\InspectionRepository;
use App\Office\NotFoundException;
use App\Settings\CompanySettingsRepository;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;

/**
 * Orchestrates document generation (D2: server-side only, after sync/finalize).
 *
 * Only `CCI` and `NNCI` have a real renderer today — they're the two types
 * evidenced in the client's own sample reports (DEV-12). `CRF`/`IDR` are
 * accepted by the schema but rejected here with a clear error until their
 * layouts are known, rather than guessing at invented fields.
 */
final class DocumentService
{
    private const SUPPORTED_TYPES = ['CCI', 'NNCI'];

    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly CciDataAssembler $assembler,
        private readonly DocumentNumberAllocator $numbers,
        private readonly DocumentSigner $signer,
        private readonly DocumentStorage $storage,
        private readonly DocumentRepository $documents,
        private readonly Renderer $renderer,
        private readonly PdfRenderer $pdf,
        private readonly CompanySettingsRepository $companySettings,
    ) {
    }

    /** @return array<string,mixed> the created document's representation */
    public function generateForInspection(string $inspectionUuid, ?int $actorId, string $type = 'CCI'): array
    {
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new ApiException(
                422,
                'unsupported_document_type',
                "`{$type}` has no template yet — only " . implode('/', self::SUPPORTED_TYPES) . ' are supported.',
                ['field' => 'type'],
            );
        }

        $inspection = $this->inspections->findByUuid($inspectionUuid) ?? throw new NotFoundException('Inspection');

        if ($inspection['status'] !== 'finalized') {
            throw new ApiException(
                422,
                'not_finalized',
                'Documents can only be generated for a finalized inspection.',
                ['status' => $inspection['status']],
            );
        }

        $documentUuid = Uuid::uuid4()->toString();
        $issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $number = $this->numbers->allocate((int) $issuedAt->format('Y'));

        $data = $this->assembler->assemble($inspectionUuid);
        $data['document'] = [
            'type'            => $type,
            'document_number' => $number,
            'issued_at'       => $issuedAt->format('Y-m-d H:i:s'),
            'copy'            => 'Original',
        ];
        $data['company'] = $this->companySettings->get();

        $html = $this->renderer->renderRaw('documents/cci', $data);
        $bytes = $this->pdf->render($html);
        $signed = $this->signer->sign($bytes);
        $stored = $this->storage->put($documentUuid, $bytes);

        $row = $this->documents->insert(
            $documentUuid,
            (int) $inspection['id'],
            $actorId,
            $type,
            $number,
            $stored['relative_path'],
            $signed['content_hash'],
            $signed['hmac_signature'],
            $issuedAt,
        );

        return Representation::document($row);
    }

    /** @return list<array<string,mixed>> */
    public function listForInspection(string $inspectionUuid): array
    {
        $inspection = $this->inspections->findByUuid($inspectionUuid) ?? throw new NotFoundException('Inspection');

        return array_map(
            [Representation::class, 'document'],
            $this->documents->forInspection((int) $inspection['id']),
        );
    }

    /** @return array{bytes:string, filename:string} */
    public function download(string $documentUuid): array
    {
        $row = $this->documents->findByUuid($documentUuid) ?? throw new NotFoundException('Document');
        $bytes = $this->storage->read((string) $row['file_path'])
            ?? throw new ApiException(410, 'file_missing', 'The document file is no longer available.');

        // A certificate is evidence, so it is checked against the hash and HMAC
        // recorded when it was issued (D7) before anyone is handed the bytes.
        // Without this the signature is only ever examined by the archive
        // export, and a PDF altered on disk — or restored from a doctored
        // backup — would be served as though it were authentic.
        if (!$this->signer->verify($bytes, (string) $row['content_hash'], (string) $row['hmac_signature'])) {
            throw new ApiException(
                409,
                'document_integrity_failed',
                'This document no longer matches the signature recorded when it was issued, so it cannot be served. Regenerate it, and report the discrepancy.',
                ['document_number' => (string) $row['document_number']],
            );
        }

        return [
            'bytes'    => $bytes,
            'filename' => $row['document_number'] . '.pdf',
        ];
    }
}
