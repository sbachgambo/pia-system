<?php

declare(strict_types=1);

namespace App\Calendar;

use DateTimeImmutable;
use PDO;

/**
 * Read-only feed for the scheduling calendar: scheduled inspections, plus the
 * notice deadline of any request that has not yet been assigned an inspector
 * (once it is scheduled, the inspection itself is the calendar entry).
 * Everything is UTC, as stored.
 */
final class CalendarEvents
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string,list<array<string,mixed>>> "Y-m-d" => events, each sorted by time
     */
    public function between(DateTimeImmutable $from, DateTimeImmutable $toExclusive, ?string $inspectorUuid = null): array
    {
        $byDay = [];
        foreach ($this->inspections($from, $toExclusive, $inspectorUuid) as $e) {
            $byDay[substr((string) $e['at'], 0, 10)][] = $e;
        }
        // Pending-request deadlines are not tied to an inspector, so hide them
        // when the calendar is filtered down to one.
        if ($inspectorUuid === null) {
            foreach ($this->deadlines($from, $toExclusive) as $e) {
                $byDay[substr((string) $e['at'], 0, 10)][] = $e;
            }
        }

        foreach ($byDay as &$events) {
            usort($events, static fn (array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));
        }

        return $byDay;
    }

    /** @return list<array{uuid:string,name:string}> */
    public function inspectors(): array
    {
        $rows = $this->pdo->query("SELECT uuid, full_name FROM users WHERE role = 'inspector' ORDER BY full_name")->fetchAll();

        return array_map(static fn (array $r): array => ['uuid' => (string) $r['uuid'], 'name' => (string) $r['full_name']], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function inspections(DateTimeImmutable $from, DateTimeImmutable $to, ?string $inspectorUuid): array
    {
        $sql = 'SELECT i.uuid, i.scheduled_at AS at, i.status, cl.name AS client_name, c.product_category, u.full_name AS inspector_name
                  FROM inspections i
                  JOIN inspection_requests r ON r.id = i.inspection_request_id
                  JOIN consignments c ON c.id = r.consignment_id
                  JOIN clients cl ON cl.id = c.client_id
                  JOIN users u ON u.id = i.inspector_id
                 WHERE i.scheduled_at >= :from AND i.scheduled_at < :to';
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        if ($inspectorUuid !== null && $inspectorUuid !== '') {
            $sql .= ' AND u.uuid = :insp';
            $params['insp'] = $inspectorUuid;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY i.scheduled_at LIMIT 2000');
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'kind'      => 'inspection',
            'uuid'      => (string) $r['uuid'],
            'at'        => (string) $r['at'],
            'status'    => (string) $r['status'],
            'client'    => (string) $r['client_name'],
            'product'   => (string) $r['product_category'],
            'inspector' => (string) $r['inspector_name'],
            'href'      => '/console/inspections/' . $r['uuid'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function deadlines(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.uuid, r.notice_deadline AS at, cl.name AS client_name, c.product_category
               FROM inspection_requests r
               JOIN consignments c ON c.id = r.consignment_id
               JOIN clients cl ON cl.id = c.client_id
              WHERE r.status = 'pending' AND r.notice_deadline >= :from AND r.notice_deadline < :to
           ORDER BY r.notice_deadline LIMIT 2000"
        );
        $stmt->execute(['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]);

        return array_map(static fn (array $r): array => [
            'kind'      => 'deadline',
            'uuid'      => (string) $r['uuid'],
            'at'        => (string) $r['at'],
            'status'    => 'deadline',
            'client'    => (string) $r['client_name'],
            'product'   => (string) $r['product_category'],
            'inspector' => '',
            'href'      => '/console/inspection-requests/' . $r['uuid'],
        ], $stmt->fetchAll());
    }
}
