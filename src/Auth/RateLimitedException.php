<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\ApiException;

/**
 * The caller has exceeded the allowed number of requests for the current
 * window. Renders as 429 `too_many_requests` with a `Retry-After` header and
 * a `retry_after` (seconds) detail.
 */
final class RateLimitedException extends ApiException
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            429,
            'too_many_requests',
            'Too many attempts. Try again later.',
            details: ['retry_after' => $retryAfterSeconds],
            headers: ['Retry-After' => (string) $retryAfterSeconds],
        );
    }
}
