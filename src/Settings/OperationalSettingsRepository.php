<?php

declare(strict_types=1);

namespace App\Settings;

use PDO;

/**
 * Workflow config editable from /console/settings instead of only via env
 * (same pattern as CompanySettingsRepository). `operational_settings` is a
 * single row (id = 1); a null column means "use the env default" — read
 * fresh every request (this app is process-per-request, see DEV_NOTES), so a
 * saved change takes effect immediately with no redeploy or cache to bust.
 */
final class OperationalSettingsRepository
{
    /** @param array{compliance_grace_hours:int} $envDefaults */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $envDefaults,
    ) {
    }

    /** @return array{compliance_grace_hours:int,require_2fa_admins:bool} */
    public function get(): array
    {
        $stmt = $this->pdo->query('SELECT compliance_grace_hours, require_2fa_admins FROM operational_settings WHERE id = 1');
        $row = $stmt !== false ? $stmt->fetch() : false;

        if ($row === false) {
            return $this->envDefaults + ['require_2fa_admins' => false];
        }

        return [
            'compliance_grace_hours' => $row['compliance_grace_hours'] !== null
                ? (int) $row['compliance_grace_hours']
                : $this->envDefaults['compliance_grace_hours'],
            'require_2fa_admins' => (bool) $row['require_2fa_admins'],
        ];
    }

    /** @return array{compliance_grace_hours:int,updated_at:?string,updated_by_name:?string} */
    public function getWithMeta(): array
    {
        $stmt = $this->pdo->query(
            'SELECT os.compliance_grace_hours, os.updated_at, u.full_name AS updated_by_name
               FROM operational_settings os LEFT JOIN users u ON u.id = os.updated_by WHERE os.id = 1'
        );
        $row = $stmt !== false ? $stmt->fetch() : false;

        if ($row === false) {
            return $this->envDefaults + ['updated_at' => null, 'updated_by_name' => null];
        }

        return [
            'compliance_grace_hours' => $row['compliance_grace_hours'] !== null
                ? (int) $row['compliance_grace_hours']
                : $this->envDefaults['compliance_grace_hours'],
            'updated_at'      => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'updated_by_name' => $row['updated_by_name'] !== null ? (string) $row['updated_by_name'] : null,
        ];
    }

    /** Make two-factor mandatory (or not) for admins and super admins. */
    public function setRequireTwoFactor(bool $required, ?int $updatedByUserId): void
    {
        $this->pdo->prepare(
            'INSERT INTO operational_settings (id, require_2fa_admins, updated_by, created_at, updated_at)
             VALUES (1, :r, :by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE require_2fa_admins = VALUES(require_2fa_admins), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()'
        )->execute(['r' => $required ? 1 : 0, 'by' => $updatedByUserId]);
    }

    public function update(int $complianceGraceHours, ?int $updatedByUserId): void
    {
        $this->pdo->prepare(
            'INSERT INTO operational_settings (id, compliance_grace_hours, updated_by, created_at, updated_at)
             VALUES (1, :grace, :by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                compliance_grace_hours = VALUES(compliance_grace_hours), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()'
        )->execute([
            'grace' => $complianceGraceHours,
            'by'    => $updatedByUserId,
        ]);
    }
}
