<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\ApiException;

/**
 * The user exists and the password was correct, but `status` = suspended.
 * Renders as 403 `account_suspended`.
 */
final class AccountSuspendedException extends ApiException
{
    public function __construct()
    {
        parent::__construct(403, 'account_suspended', 'This account is suspended.');
    }
}
