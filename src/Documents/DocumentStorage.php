<?php

declare(strict_types=1);

namespace App\Documents;

use RuntimeException;

/**
 * Filesystem store for generated document PDFs, mirroring
 * App\Inspections\AttachmentStorage: files live under `storage/documents/`,
 * OUTSIDE the web root, named by the document's own uuid so a retry can
 * never collide, and are only ever served back through an authenticated
 * endpoint (GET /api/documents/{uuid}, brief §5).
 */
final class DocumentStorage
{
    public function __construct(private readonly string $baseDir)
    {
    }

    /** @return array{path:string, relative_path:string, byte_size:int} */
    public function put(string $documentUuid, string $bytes): array
    {
        $this->ensureDir();

        $name = $this->safeName($documentUuid) . '.pdf';
        $abs  = $this->baseDir . DIRECTORY_SEPARATOR . $name;

        if (file_put_contents($abs, $bytes, LOCK_EX) === false) {
            throw new RuntimeException("Could not write document to {$abs}");
        }

        return ['path' => $abs, 'relative_path' => 'documents/' . $name, 'byte_size' => strlen($bytes)];
    }

    public function read(string $relativePath): ?string
    {
        $abs = $this->resolve($relativePath);

        if ($abs === null || !is_file($abs)) {
            return null;
        }

        $bytes = file_get_contents($abs);

        return $bytes === false ? null : $bytes;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0775, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException("Document directory {$this->baseDir} is not writable.");
        }
    }

    private function safeName(string $uuid): string
    {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            throw new RuntimeException('Invalid document identifier.');
        }

        return strtolower($uuid);
    }

    private function resolve(string $relativePath): ?string
    {
        $name = basename(str_replace('\\', '/', $relativePath));
        $abs  = $this->baseDir . DIRECTORY_SEPARATOR . $name;
        $real = realpath($abs);
        $realBase = realpath($this->baseDir);

        if ($real === false || $realBase === false || !str_starts_with($real, $realBase)) {
            return null;
        }

        return $real;
    }
}
