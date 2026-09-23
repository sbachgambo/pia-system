<?php

declare(strict_types=1);

namespace App\Returns;

use RuntimeException;

/**
 * Filesystem store for generated return files, mirroring
 * App\Documents\DocumentStorage: files live under `storage/returns/`,
 * outside the web root, named by the return's own uuid.
 */
final class StatutoryReturnStorage
{
    public function __construct(private readonly string $baseDir)
    {
    }

    public function put(string $uuid, string $contents, string $extension): array
    {
        $this->ensureDir();

        $name = $this->safeName($uuid) . '.' . $extension;
        $abs  = $this->baseDir . DIRECTORY_SEPARATOR . $name;

        if (file_put_contents($abs, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Could not write return file to {$abs}");
        }

        return ['path' => $abs, 'relative_path' => 'returns/' . $name];
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
            throw new RuntimeException("Returns directory {$this->baseDir} is not writable.");
        }
    }

    private function safeName(string $uuid): string
    {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            throw new RuntimeException('Invalid return identifier.');
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
