<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\ApiException;

/**
 * The refresh-token chain has passed the D4 7-day cap counted from the last
 * online login. The client must perform a full online login again. Renders as
 * 401 `reauth_required` (distinct from `invalid_token` so the app knows to
 * show the login screen rather than just retry).
 */
final class ReauthRequiredException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            401,
            'reauth_required',
            'Your offline session has reached its 7-day limit. Please sign in online again.',
        );
    }
}
