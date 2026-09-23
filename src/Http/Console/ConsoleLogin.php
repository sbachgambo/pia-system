<?php

declare(strict_types=1);

namespace App\Http\Console;

use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\Csrf;
use App\Http\Support\SessionStore;

/**
 * The one place a console session is actually established. Called after the
 * password step for ordinary accounts, and after the second factor for
 * accounts that have one — so both paths end in exactly the same state.
 */
final class ConsoleLogin
{
    public const PENDING_UUID = 'pending_2fa_uuid';
    public const PENDING_AT = 'pending_2fa_at';
    public const PENDING_TRIES = 'pending_2fa_tries';

    /** @return string where to send the user next (only ever a /console path) */
    public function complete(SessionStore $session, Csrf $csrf, string $userUuid): string
    {
        // Fresh session id on privilege change (fixation defence).
        session_regenerate_id(true);
        $session->set(ConsoleAuthMiddleware::SESSION_KEY, $userUuid);
        $session->set(ConsoleAuthMiddleware::LOGIN_AT_KEY, time());
        $session->remove(self::PENDING_UUID);
        $session->remove(self::PENDING_AT);
        $session->remove(self::PENDING_TRIES);
        $csrf->rotate();

        $intended = $session->get(ConsoleAuthMiddleware::INTENDED_KEY);
        $session->remove(ConsoleAuthMiddleware::INTENDED_KEY);

        return is_string($intended) && str_starts_with($intended, '/console') ? $intended : '/console';
    }
}
