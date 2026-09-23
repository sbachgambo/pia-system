<?php

declare(strict_types=1);

namespace App\Invoicing;

use RuntimeException;

/**
 * Filesystem store for issued CBN invoice PDFs, mirroring DocumentStorage:
 * files live under `storage/invoices/`, outside the web root, named by the
 * invoice's uuid, and reads are confined to that directory.
 */
final class InvoiceStorage
{
    public function __construct(private readonly string $baseDir)
    {
    }

    /** @return array{path:string, relative_path:string} */
    public function put(string $uuid, string $bytes): array
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0775, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException("Invoice directory {$this->baseDir} is not writable.");
        }
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            throw new RuntimeException('Invalid invoice identifier.');
        }

        $name = strtolower($uuid) . '.pdf';
        $abs = $this->baseDir . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($abs, $bytes, LOCK_EX) === false) {
            throw new RuntimeException("Could not write invoice to {$abs}");
        }

        return ['path' => $abs, 'relative_path' => 'invoices/' . $name];
    }

    public function read(string $relativePath): ?string
    {
        $abs = $this->baseDir . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', $relativePath));
        $real = realpath($abs);
        $realBase = realpath($this->baseDir);

        if ($real === false || $realBase === false || !str_starts_with($real, $realBase) || !is_file($real)) {
            return null;
        }

        $bytes = file_get_contents($real);

        return $bytes === false ? null : $bytes;
    }
}
