<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * Document integrity per D7: a SHA-256 content hash plus an HMAC-SHA256
 * signature over that hash, keyed by `security.hmac_document_key`. No PKI
 * certificate relationship exists yet (D7's rationale) — this is
 * tamper-evidence, upgradable later to a real digital signature without a
 * schema change (brief §7).
 */
final class DocumentSigner
{
    public function __construct(private readonly string $hmacKey)
    {
    }

    /** @return array{content_hash:string, hmac_signature:string} */
    public function sign(string $bytes): array
    {
        $hash = hash('sha256', $bytes);

        return [
            'content_hash'   => $hash,
            'hmac_signature' => hash_hmac('sha256', $hash, $this->hmacKey),
        ];
    }

    public function verify(string $bytes, string $expectedHash, string $expectedSignature): bool
    {
        $signed = $this->sign($bytes);

        return hash_equals($expectedHash, $signed['content_hash'])
            && hash_equals($expectedSignature, $signed['hmac_signature']);
    }
}
