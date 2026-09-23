<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * An inspection has at most one live certificate (DocumentRepository::insert
 * now voids the one a new certificate replaces). Before that rule, generating
 * again left every version `issued`, so the CBN invoice and the income report
 * counted the shipment once per version.
 *
 * This brings existing data in line: for each inspection with several issued
 * certificates, the newest stays issued and the older ones become `void` —
 * exactly what the new rule would have done at the time. Nothing is deleted.
 *
 * Irreversible by nature (which rows were voided here isn't recorded apart
 * from the audit trail of this migration), so down() does nothing.
 */
final class OneLiveCertificatePerInspection extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "UPDATE documents d
               JOIN (SELECT inspection_id, MAX(id) AS keep_id FROM documents
                      WHERE status = 'issued' GROUP BY inspection_id HAVING COUNT(*) > 1) k
                 ON k.inspection_id = d.inspection_id
                SET d.status = 'void', d.updated_at = UTC_TIMESTAMP()
              WHERE d.status = 'issued' AND d.id <> k.keep_id"
        );
    }

    public function down(): void
    {
    }
}
