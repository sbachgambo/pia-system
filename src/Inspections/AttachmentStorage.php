<?php

declare(strict_types=1);

namespace App\Inspections;

use App\Support\ApiException;
use RuntimeException;

/**
 * Filesystem store for inspection attachment binaries.
 *
 *  - Files live under `storage/attachments/`, OUTSIDE the web root; they are
 *    only ever served back through an authenticated endpoint (DEV-3).
 *  - The stored name is derived from the attachment's client uuid, so a
 *    retried upload overwrites rather than duplicates (sync idempotency, §3).
 *  - The caller supplies the SHA-256 it expects; the bytes are rejected if
 *    they don't match (brief §4: "integrity check on upload").
 *  - MIME type is sniffed from the bytes, not trusted from the request.
 */
final class AttachmentStorage
{
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly string $baseDir,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * Persist raw bytes for an attachment.
     *
     * @return array{path:string, relative_path:string, mime_type:string, byte_size:int, checksum_sha256:string}
     * @throws ApiException on size / checksum / type violations
     */
    public function put(string $clientUuid, string $bytes, string $expectedSha256): array
    {
        $size = strlen($bytes);

        if ($size === 0) {
            throw new ApiException(422, 'empty_file', 'The uploaded file is empty.');
        }
        if ($size > $this->maxBytes) {
            throw new ApiException(413, 'file_too_large', "File exceeds the {$this->maxBytes}-byte limit.");
        }

        $actual = hash('sha256', $bytes);
        if (!hash_equals(strtolower($expectedSha256), $actual)) {
            throw new ApiException(422, 'checksum_mismatch', 'Uploaded bytes do not match the declared SHA-256.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new ApiException(415, 'unsupported_type', "File type {$mime} is not allowed.");
        }

        $this->ensureDir();

        $name = $this->safeName($clientUuid) . '.' . self::ALLOWED_MIME[$mime];
        $abs  = $this->baseDir . DIRECTORY_SEPARATOR . $name;

        if (file_put_contents($abs, $bytes, LOCK_EX) === false) {
            throw new RuntimeException("Could not write attachment to {$abs}");
        }

        return [
            'path'            => $abs,
            'relative_path'   => 'attachments/' . $name,
            'mime_type'       => $mime,
            'byte_size'       => $size,
            'checksum_sha256' => $actual,
        ];
    }

    /** @return array{bytes:string, mime_type:string}|null */
    public function read(string $relativePath): ?array
    {
        $abs = $this->resolve($relativePath);

        if ($abs === null || !is_file($abs)) {
            return null;
        }

        $bytes = file_get_contents($abs);
        if ($bytes === false) {
            return null;
        }

        return [
            'bytes'     => $bytes,
            'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream',
        ];
    }

    public function delete(string $relativePath): void
    {
        $abs = $this->resolve($relativePath);
        if ($abs !== null && is_file($abs)) {
            @unlink($abs);
        }
    }

    // --- internals -----------------------------------------------------

    private function ensureDir(): void
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0775, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException("Attachment directory {$this->baseDir} is not writable.");
        }
    }

    private function safeName(string $clientUuid): string
    {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $clientUuid)) {
            throw new ApiException(422, 'validation_failed', 'Invalid attachment identifier.', ['field' => 'client_uuid']);
        }

        return strtolower($clientUuid);
    }

    /** Resolve a relative path and confirm it stays inside baseDir. */
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
