<?php

declare(strict_types=1);

namespace App\Settings;

use PDO;

/**
 * The document letterhead (Phase 6 follow-up) — editable at
 * /console/settings instead of only via env. `company_settings` is a
 * single row (id = 1); until an admin saves a change, `get()` falls back to
 * the env-level defaults in `config/settings.php` so a fresh install still
 * has something sensible to print.
 */
final class CompanySettingsRepository
{
    /** @param array{name:string,address:string,representative_title:string} $envDefaults */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $envDefaults,
    ) {
    }

    /** @return array{name:string,address:string,representative_title:string} */
    public function get(): array
    {
        $stmt = $this->pdo->query('SELECT name, address, representative_title FROM company_settings WHERE id = 1');
        $row = $stmt !== false ? $stmt->fetch() : false;

        if ($row === false) {
            return $this->envDefaults;
        }

        return [
            'name'                  => (string) $row['name'],
            'address'               => (string) ($row['address'] ?? ''),
            'representative_title'  => (string) $row['representative_title'],
        ];
    }

    /** @param array{name:string,address:?string,representative_title:string} $data */
    public function update(array $data, ?int $updatedByUserId): void
    {
        $this->pdo->prepare(
            'INSERT INTO company_settings (id, name, address, representative_title, updated_by, created_at, updated_at)
             VALUES (1, :name, :address, :title, :by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), address = VALUES(address), representative_title = VALUES(representative_title),
                updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()'
        )->execute([
            'name'    => $data['name'],
            'address' => $data['address'] ?? null,
            'title'   => $data['representative_title'],
            'by'      => $updatedByUserId,
        ]);
    }

    /** @return array{name:string,address:string,representative_title:string,updated_at:?string,updated_by_name:?string} */
    public function getWithMeta(): array
    {
        $stmt = $this->pdo->query(
            'SELECT cs.name, cs.address, cs.representative_title, cs.updated_at, u.full_name AS updated_by_name
               FROM company_settings cs LEFT JOIN users u ON u.id = cs.updated_by WHERE cs.id = 1'
        );
        $row = $stmt !== false ? $stmt->fetch() : false;

        if ($row === false) {
            return $this->envDefaults + ['updated_at' => null, 'updated_by_name' => null];
        }

        return [
            'name'                 => (string) $row['name'],
            'address'              => (string) ($row['address'] ?? ''),
            'representative_title' => (string) $row['representative_title'],
            'updated_at'           => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'updated_by_name'      => $row['updated_by_name'] !== null ? (string) $row['updated_by_name'] : null,
        ];
    }
}
