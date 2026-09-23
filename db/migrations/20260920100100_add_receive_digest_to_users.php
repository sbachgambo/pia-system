<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** users.receive_digest — opt-out for the daily "needs attention" email (default on). */
final class AddReceiveDigestToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('receive_digest', 'boolean', ['null' => false, 'default' => true, 'after' => 'status'])
            ->update();
    }
}
