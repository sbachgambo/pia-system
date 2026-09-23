<?php

declare(strict_types=1);

namespace App\Http\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Pulls the caller's IP and device id off a request.
 *
 * IP: uses the connection's REMOTE_ADDR only. `X-Forwarded-For` is NOT trusted
 * by default — on shared hosting we don't control the proxy chain, and a
 * spoofed header would let an attacker sidestep rate limiting. If the host is
 * later confirmed to sit behind a known proxy, add an allow-list check here.
 */
final class ClientContext
{
    public static function ip(ServerRequestInterface $request): string
    {
        $params = $request->getServerParams();
        $ip = $params['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }

    /** Optional client-supplied device identifier, bounded and sanitised. */
    public static function deviceId(ServerRequestInterface $request): ?string
    {
        $value = $request->getHeaderLine('X-Device-Id');

        if ($value === '' || strlen($value) > 100 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return null;
        }

        return $value;
    }
}
