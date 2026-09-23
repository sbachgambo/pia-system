<?php

declare(strict_types=1);

namespace App\Http\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads query-string parameters. Normally `getQueryParams()` is populated (the
 * front controller builds the request from globals), but a request assembled
 * by hand (tests, or a non-standard entry point) may only carry the raw query
 * on the URI — so fall back to parsing that.
 */
final class Query
{
    /** @return array<string,mixed> */
    public static function params(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        if ($params === []) {
            $raw = $request->getUri()->getQuery();
            if ($raw !== '') {
                parse_str($raw, $params);
            }
        }

        return $params;
    }
}
