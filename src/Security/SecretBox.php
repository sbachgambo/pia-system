<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Authenticated encryption (AES-256-GCM) for secrets that must be recoverable
 * but shouldn't sit in the database in the clear — here, users' TOTP seeds. The
 * key is derived from APP_KEY, so a database dump alone (a stolen backup, a SQL
 * injection) doesn't yield working second factors; an attacker would need the
 * server's .env as well.
 */
final class SecretBox
{
    private const PREFIX = 'v1:';

    private readonly string $key;

    public function __construct(string $appKey)
    {
        $this->key = hash('sha256', 'pia-secretbox-v1|' . $appKey, true);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** @return string|null null when the value is malformed, tampered with, or was encrypted under another key */
    public function decrypt(string $boxed): ?string
    {
        if (!str_starts_with($boxed, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($boxed, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }
}
