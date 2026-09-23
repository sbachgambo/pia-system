<?php

declare(strict_types=1);

namespace App\Search;

use PDO;

/**
 * Console-wide search: one query string, a few capped result groups. Read-only
 * and prepared throughout. LIKE wildcards in the user's input are escaped so a
 * search for "50%" or "a_b" matches literally instead of as a pattern.
 */
final class GlobalSearch
{
    public const MIN_LENGTH = 2;
    private const PER_GROUP = 8;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{clients:list<array<string,mixed>>,consignments:list<array<string,mixed>>,inspections:list<array<string,mixed>>,documents:list<array<string,mixed>>,users:list<array<string,mixed>>}
     */
    public function search(string $query, bool $includeUsers): array
    {
        $empty = ['clients' => [], 'consignments' => [], 'inspections' => [], 'documents' => [], 'users' => []];
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return $empty;
        }

        $like = '%' . addcslashes($query, '%_\\') . '%';

        return [
            'clients'      => $this->clients($like),
            'consignments' => $this->consignments($like),
            'inspections'  => $this->inspections($like),
            'documents'    => $this->documents($like),
            'users'        => $includeUsers ? $this->users($like) : [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function clients(string $like): array
    {
        return $this->run(
            'SELECT uuid, name, type, rc_number, contact_name, contact_email FROM clients
              WHERE name LIKE :a OR rc_number LIKE :b OR contact_name LIKE :c OR contact_email LIKE :d
           ORDER BY name ASC LIMIT ' . self::PER_GROUP,
            ['a' => $like, 'b' => $like, 'c' => $like, 'd' => $like],
        );
    }

    /** @return list<array<string,mixed>> */
    private function consignments(string $like): array
    {
        return $this->run(
            'SELECT c.uuid, c.product_category, c.product_description, c.hs_code, c.form_nxp_number, c.zone, cl.name AS client_name
               FROM consignments c JOIN clients cl ON cl.id = c.client_id
              WHERE c.product_category LIKE :a OR c.product_description LIKE :b OR c.hs_code LIKE :c
                 OR c.form_nxp_number LIKE :d OR cl.name LIKE :e
           ORDER BY c.created_at DESC LIMIT ' . self::PER_GROUP,
            ['a' => $like, 'b' => $like, 'c' => $like, 'd' => $like, 'e' => $like],
        );
    }

    /** @return list<array<string,mixed>> */
    private function inspections(string $like): array
    {
        return $this->run(
            'SELECT i.uuid, i.status, i.scheduled_at, cl.name AS client_name, c.product_category, c.form_nxp_number
               FROM inspections i
               JOIN inspection_requests r ON r.id = i.inspection_request_id
               JOIN consignments c ON c.id = r.consignment_id
               JOIN clients cl ON cl.id = c.client_id
              WHERE cl.name LIKE :a OR c.product_category LIKE :b OR i.uuid LIKE :c OR c.form_nxp_number LIKE :d
           ORDER BY i.scheduled_at DESC LIMIT ' . self::PER_GROUP,
            ['a' => $like, 'b' => $like, 'c' => $like, 'd' => $like],
        );
    }

    /** @return list<array<string,mixed>> */
    private function documents(string $like): array
    {
        return $this->run(
            'SELECT d.uuid, d.document_number, d.type, d.status, d.issued_at, i.uuid AS inspection_uuid, cl.name AS client_name,
                    c.form_nxp_number
               FROM documents d
               JOIN inspections i ON i.id = d.inspection_id
               JOIN inspection_requests r ON r.id = i.inspection_request_id
               JOIN consignments c ON c.id = r.consignment_id
               JOIN clients cl ON cl.id = c.client_id
              WHERE d.document_number LIKE :a OR cl.name LIKE :b OR c.form_nxp_number LIKE :c
           ORDER BY d.issued_at DESC LIMIT ' . self::PER_GROUP,
            ['a' => $like, 'b' => $like, 'c' => $like],
        );
    }

    /** @return list<array<string,mixed>> */
    private function users(string $like): array
    {
        return $this->run(
            'SELECT uuid, full_name, email, role, status FROM users
              WHERE full_name LIKE :a OR email LIKE :b ORDER BY full_name ASC LIMIT ' . self::PER_GROUP,
            ['a' => $like, 'b' => $like],
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function run(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
