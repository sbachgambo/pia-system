<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Board of Directors access (client request, Sep 2026): a `board` user signs in
 * to the office console and can see everything an admin can see — income, CBN
 * invoices, returns, compliance, the audit log, the archive and every record —
 * but cannot create, change or delete anything. The read-only rule is enforced
 * by BoardReadOnlyMiddleware, not by the role list here.
 */
final class AddBoardRole extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE users MODIFY role ENUM('inspector','office_reviewer','admin','super_admin','board') NOT NULL"
        );
    }

    public function down(): void
    {
        // A board account has no place in the narrower enum; refuse rather
        // than silently turn it into some other role.
        $count = (int) $this->fetchRow("SELECT COUNT(*) AS n FROM users WHERE role = 'board'")['n'];
        if ($count > 0) {
            throw new RuntimeException("{$count} board user(s) exist; change or remove them before rolling back.");
        }
        $this->execute(
            "ALTER TABLE users MODIFY role ENUM('inspector','office_reviewer','admin','super_admin') NOT NULL"
        );
    }
}
