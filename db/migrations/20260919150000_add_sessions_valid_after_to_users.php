<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * users.sessions_valid_after — "sign out everywhere".
 *
 * Console sessions are native PHP cookie sessions, so there is no server-side
 * list of them to delete. Instead each session records the time it was
 * created, and ConsoleAuthMiddleware rejects any session that started before
 * this timestamp. NULL = never revoked. Set by an admin's "sign out
 * everywhere", and automatically on password reset and suspension.
 */
final class AddSessionsValidAfterToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('sessions_valid_after', 'datetime', ['null' => true, 'after' => 'last_login_at'])
            ->update();
    }
}
