<?php

declare(strict_types=1);

namespace App\Archive;

use App\Documents\DocumentSigner;
use App\Documents\DocumentStorage;
use App\Invoicing\InvoiceStorage;
use App\Returns\CsvWriter;
use App\Returns\StatutoryReturnStorage;
use App\Support\ApiException;
use RuntimeException;
use ZipArchive;

/**
 * Bulk export of the archive: the files matching a filter, in one ZIP, filed
 * as {kind}/{year}/{month}/{number}.{ext} with an index.csv manifest.
 *
 * Signed files (certificates, invoices) are re-verified before they go in; a
 * file that no longer matches its signature — or has gone missing — is left
 * out and listed as such in the manifest, never silently included.
 */
final class ArchiveService
{
    public const MAX_FILES = 500;

    public function __construct(
        private readonly ArchiveRepository $archive,
        private readonly DocumentStorage $documents,
        private readonly InvoiceStorage $invoices,
        private readonly StatutoryReturnStorage $returns,
        private readonly DocumentSigner $signer,
        private readonly string $tempDir,
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{path:string, filename:string, included:int, skipped:int}
     *         `path` is a temporary file the caller must delete after sending
     */
    public function zip(array $filters, bool $includeAdmin): array
    {
        $result = $this->archive->search($filters, $includeAdmin, self::MAX_FILES + 1, 0);
        if ($result['total'] === 0) {
            throw new ApiException(422, 'nothing_to_export', 'No archived documents match these filters.');
        }
        if ($result['total'] > self::MAX_FILES) {
            throw new ApiException(422, 'too_many', 'That is ' . $result['total'] . ' documents; a download is limited to '
                . self::MAX_FILES . '. Narrow the dates or filters and download in parts.');
        }

        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0775, true) && !is_dir($this->tempDir)) {
            throw new RuntimeException("Temp directory {$this->tempDir} is not writable.");
        }
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'archive-' . bin2hex(random_bytes(8)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create the archive file.');
        }

        $manifest = [];
        $included = $skipped = 0;
        $used = [];
        foreach ($result['rows'] as $row) {
            $bytes = $this->read($row);
            $note = 'included';

            if ($bytes === null) {
                $note = 'file missing - not included';
            } elseif ($row['content_hash'] !== null
                && !$this->signer->verify($bytes, (string) $row['content_hash'], (string) $row['hmac_signature'])) {
                $note = 'failed integrity check - not included';
                $bytes = null;
            }

            $entry = '';
            if ($bytes !== null) {
                $entry = $this->entryName($row, $used);
                $zip->addFromString($entry, $bytes);
                $included++;
            } else {
                $skipped++;
            }

            $manifest[] = [
                (string) $row['kind'], (string) $row['type'], (string) $row['number'], (string) $row['issued_at'],
                (string) $row['status'], (string) $row['client_name'], (string) ($row['nxp_number'] ?? ''), $entry, $note,
            ];
        }

        $zip->addFromString('index.csv', CsvWriter::writeSafe(
            ['Kind', 'Type', 'Number', 'Issued (UTC)', 'Status', 'Client / agency', 'NXP no.', 'File in this ZIP', 'Note'],
            $manifest,
        ));
        $zip->close();

        return [
            'path'     => $path,
            'filename' => 'adwol-archive-' . gmdate('Ymd-His') . '.zip',
            'included' => $included,
            'skipped'  => $skipped,
        ];
    }

    /** @param array<string,mixed> $row */
    private function read(array $row): ?string
    {
        $file = (string) $row['file_path'];

        return match ($row['kind']) {
            'certificate' => $this->documents->read($file),
            'invoice'     => $this->invoices->read($file),
            'return'      => $this->returns->read($file),
            default       => null,
        };
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,true>  $used names already in the ZIP (made unique)
     */
    private function entryName(array $row, array &$used): string
    {
        $folder = ['certificate' => 'certificates', 'invoice' => 'cbn-invoices', 'return' => 'statutory-returns'][$row['kind']] ?? 'other';
        $date = substr((string) $row['issued_at'], 0, 7); // YYYY-MM
        $label = $row['kind'] === 'certificate' ? $row['type'] . ' ' . $row['number'] : (string) $row['number'];
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '-', $label), ' .-');
        $base = sprintf('%s/%s/%s/%s', $folder, substr($date, 0, 4), substr($date, 5, 2), $safe !== '' ? $safe : 'document');
        $ext = (string) $row['format'];

        $name = "{$base}.{$ext}";
        for ($n = 2; isset($used[$name]); $n++) {
            $name = "{$base} ({$n}).{$ext}";
        }
        $used[$name] = true;

        return $name;
    }
}
