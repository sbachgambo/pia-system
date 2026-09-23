<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Base class for tests that touch the (test) database. Provides a shared PDO
 * and truncates the tables a test declares dirty, in setUp, so each test
 * starts from a known-empty state.
 *
 * Not named *Test so PHPUnit does not try to run it.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    /** @return array<string,mixed> */
    protected function settings(): array
    {
        return require dirname(__DIR__, 2) . '/config/settings.php';
    }

    protected function setUp(): void
    {
        $this->pdo = Database::connect($this->settings()['db']);
        $this->truncate($this->dirtyTables());
    }

    /** @return list<string> tables this test writes to */
    protected function dirtyTables(): array
    {
        return [];
    }

    /** @param list<string> $tables */
    protected function truncate(array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Insert a user row directly and return it (id + uuid + plaintext creds).
     *
     * @param array<string,mixed> $overrides
     * @return array{id:int, uuid:string, email:string, password:string}
     */
    protected function makeUser(array $overrides = []): array
    {
        $uuid     = $overrides['uuid'] ?? Uuid::uuid4()->toString();
        $email    = $overrides['email'] ?? ('user_' . substr($uuid, 0, 8) . '@adwol.test');
        $password = $overrides['password'] ?? 'Correct-Horse-9';
        $role     = $overrides['role'] ?? 'inspector';
        $status   = $overrides['status'] ?? 'active';
        $zone     = $overrides['zone'] ?? null;
        // Cheap Argon2id params for fixtures — keeps the suite fast. Production
        // cost is exercised via config in the real login path, not here.
        $hash     = $overrides['password_hash']
            ?? password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (uuid, full_name, email, password_hash, role, zone, status, created_at, updated_at)
             VALUES (:uuid, :name, :email, :hash, :role, :zone, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uuid'   => $uuid,
            'name'   => $overrides['full_name'] ?? 'Test User',
            'email'  => $email,
            'hash'   => $hash,
            'role'   => $role,
            'zone'   => $zone,
            'status' => $status,
        ]);

        return [
            'id'       => (int) $this->pdo->lastInsertId(),
            'uuid'     => $uuid,
            'email'    => $email,
            'password' => $password,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{id:int, uuid:string}
     */
    protected function makeClientRow(array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? Uuid::uuid4()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (uuid, name, type, rc_number, address, contact_name, contact_phone, contact_email, created_at, updated_at)
             VALUES (:uuid, :name, :type, :rc, :addr, :cn, :cp, :ce, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'name' => $overrides['name'] ?? 'Fixture Client ' . substr($uuid, 0, 8),
            'type' => $overrides['type'] ?? 'exporter',
            'rc'   => $overrides['rc_number'] ?? null,
            'addr' => $overrides['address'] ?? '1 Test Street',
            'cn'   => $overrides['contact_name'] ?? 'Contact',
            'cp'   => $overrides['contact_phone'] ?? '+2348000000000',
            'ce'   => $overrides['contact_email'] ?? 'c@fixture.test',
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'uuid' => $uuid];
    }

    /**
     * @param array<string,mixed> $overrides  must include client_id
     * @return array{id:int, uuid:string}
     */
    protected function makeConsignmentRow(int $clientId, array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? Uuid::uuid4()->toString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO consignments
                (uuid, client_id, direction, product_category, product_description, hs_code, quantity,
                 unit_of_measure, declared_value, currency, origin_country, destination_country, zone,
                 form_nxp_number, created_at, updated_at)
             VALUES (:uuid, :cid, :dir, :pc, :pd, :hs, :qty, :uom, :dv, :cur, :oc, :dc, :zone, :nxp,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'cid'  => $clientId,
            'dir'  => $overrides['direction'] ?? 'export',
            'pc'   => $overrides['product_category'] ?? 'Cocoa',
            'pd'   => $overrides['product_description'] ?? 'Dried cocoa beans',
            'hs'   => $overrides['hs_code'] ?? null,
            'qty'  => $overrides['quantity'] ?? '1000.00',
            'uom'  => $overrides['unit_of_measure'] ?? 'kg',
            'dv'   => $overrides['declared_value'] ?? '50000.00',
            'cur'  => $overrides['currency'] ?? 'USD',
            'oc'   => $overrides['origin_country'] ?? 'Nigeria',
            'dc'   => $overrides['destination_country'] ?? 'Netherlands',
            'zone' => $overrides['zone'] ?? 'Lagos',
            'nxp'  => $overrides['form_nxp_number'] ?? null,
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'uuid' => $uuid];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{id:int, uuid:string}
     */
    protected function makeInspectionRequestRow(int $consignmentId, int $requestedBy, array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? Uuid::uuid4()->toString();

        $this->pdo->prepare(
            'INSERT INTO inspection_requests
                (uuid, consignment_id, requested_by, requested_at, notice_deadline, status, created_at, updated_at)
             VALUES (:uuid, :cid, :by, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 DAY), :status,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'uuid'   => $uuid,
            'cid'    => $consignmentId,
            'by'     => $requestedBy,
            'status' => $overrides['status'] ?? 'pending',
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'uuid' => $uuid];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{id:int, uuid:string}
     */
    protected function makeScheduledInspectionRow(int $requestId, int $inspectorId, array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? Uuid::uuid4()->toString();

        $this->pdo->prepare(
            'INSERT INTO inspections
                (uuid, inspection_request_id, inspector_id, scheduled_at, location_type, location_detail,
                 status, started_at, synced_at, device_id, created_at, updated_at)
             VALUES (:uuid, :req, :insp, :sched, :ltype, :ldetail, :status, :started, :synced, :device,
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'uuid'    => $uuid,
            'req'     => $requestId,
            'insp'    => $inspectorId,
            'sched'   => $overrides['scheduled_at'] ?? gmdate('Y-m-d H:i:s'),
            'ltype'   => $overrides['location_type'] ?? 'warehouse',
            'ldetail' => $overrides['location_detail'] ?? 'Bay 4',
            'status'  => $overrides['status'] ?? 'scheduled',
            'started' => $overrides['started_at'] ?? null,
            'synced'  => $overrides['synced_at'] ?? null,
            'device'  => $overrides['device_id'] ?? null,
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'uuid' => $uuid];
    }

    /**
     * A certificate row (no file on disk unless the test writes one).
     *
     * @param array<string,mixed> $overrides type, document_number, issued_at, status, file_path, content_hash, hmac_signature, issued_by
     * @return array{id:int, uuid:string, document_number:string}
     */
    protected function makeDocumentRow(int $inspectionId, array $overrides = []): array
    {
        $uuid = $overrides['uuid'] ?? Uuid::uuid4()->toString();
        $number = $overrides['document_number'] ?? gmdate('Y') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);

        $this->pdo->prepare(
            'INSERT INTO documents
                (uuid, inspection_id, issued_by, type, document_number, file_path, content_hash, hmac_signature,
                 issued_at, status, created_at, updated_at)
             VALUES (:uuid, :insp, :by, :type, :num, :path, :hash, :sig, :at, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([
            'uuid'   => $uuid,
            'insp'   => $inspectionId,
            'by'     => $overrides['issued_by'] ?? null,
            'type'   => $overrides['type'] ?? 'CCI',
            'num'    => $number,
            'path'   => $overrides['file_path'] ?? 'documents/' . $uuid . '.pdf',
            'hash'   => $overrides['content_hash'] ?? str_repeat('0', 64),
            'sig'    => $overrides['hmac_signature'] ?? str_repeat('0', 64),
            'at'     => $overrides['issued_at'] ?? gmdate('Y-m-d H:i:s'),
            'status' => $overrides['status'] ?? 'issued',
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'uuid' => $uuid, 'document_number' => $number];
    }
}
