<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\ApiException;
use Throwable;

/**
 * An access or refresh token is missing, malformed, expired, revoked, or
 * (for refresh tokens) already used. Renders as 401 `invalid_token`.
 */
final class InvalidTokenException extends ApiException
{
    public function __construct(string $message = 'Invalid or expired token.', ?Throwable $previous = null)
    {
        parent::__construct(401, 'invalid_token', $message, previous: $previous);
    }
}
