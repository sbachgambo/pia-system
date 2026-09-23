<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\ApiException;
use Throwable;

/**
 * Wrong email or password (also raised for an unknown email, so the two are
 * indistinguishable to a caller). Renders as 401 `invalid_credentials`.
 */
final class InvalidCredentialsException extends ApiException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(401, 'invalid_credentials', 'Email or password is incorrect.', previous: $previous);
    }
}
