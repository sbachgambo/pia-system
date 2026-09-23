<?php

declare(strict_types=1);

namespace App\Notifications;

use PDO;

/**
 * "Needs attention" — the console's notification bell. Deliberately *derived*
 * from live data rather than stored: an item is on the list exactly as long as
 * the underlying condition is true (an inspection waiting for review, a
 * request past its notice deadline…), so there is no read/unread state to
 * drift, no background job to run, and nothing to clean up. Resolving the work
 * is what clears the item.
 */
final class AttentionFeed
{
    private const LIMIT = 10;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{review:int,overdue:int,due_soon:int,alerts:int,total:int} */
    public function counts(bool $includeAdmin): array
    {
        $review = (int) $this->pdo->query("SELECT COUNT(*) FROM inspections WHERE status IN ('synced', 'amended')")->fetchColumn();
        $overdue = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM inspection_requests WHERE status IN ('pending', 'scheduled') AND notice_deadline < UTC_TIMESTAMP()"
        )->fetchColumn();
        $dueSoon = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM inspection_requests
              WHERE status = 'pending' AND notice_deadline >= UTC_TIMESTAMP() AND notice_deadline < (UTC_TIMESTAMP() + INTERVAL 24 HOUR)"
        )->fetchColumn();
        $alerts = $includeAdmin ? $this->complianceAlerts() : 0;

        return [
            'review'   => $review,
            'overdue'  => $overdue,
            'due_soon' => $dueSoon,
            'alerts'   => $alerts,
            'total'    => $review + $overdue + $dueSoon + $alerts,
        ];
    }

    /**
     * @return array{review:list<array<string,mixed>>,overdue:list<array<string,mixed>>,due_soon:list<array<string,mixed>>,alerts:list<array<string,mixed>>}
     */
    public function items(bool $includeAdmin): array
    {
        $limit = self::LIMIT;
        $requestSql = static fn (string $where, string $order): string => "SELECT r.uuid, r.notice_deadline, cl.name AS client_name, c.product_category
               FROM inspection_requests r
               JOIN consignments c ON c.id = r.consignment_id
               JOIN clients cl ON cl.id = c.client_id
              WHERE {$where} ORDER BY {$order} LIMIT {$limit}";

        return [
            'review' => $this->pdo->query(
                "SELECT i.uuid, i.status, i.synced_at, cl.name AS client_name, c.product_category, u.full_name AS inspector_name
                   FROM inspections i
                   JOIN inspection_requests r ON r.id = i.inspection_request_id
                   JOIN consignments c ON c.id = r.consignment_id
                   JOIN clients cl ON cl.id = c.client_id
                   JOIN users u ON u.id = i.inspector_id
                  WHERE i.status IN ('synced', 'amended') ORDER BY i.synced_at ASC LIMIT {$limit}"
            )->fetchAll(),
            'overdue' => $this->pdo->query($requestSql(
                "r.status IN ('pending', 'scheduled') AND r.notice_deadline < UTC_TIMESTAMP()",
                'r.notice_deadline ASC',
            ))->fetchAll(),
            'due_soon' => $this->pdo->query($requestSql(
                "r.status = 'pending' AND r.notice_deadline >= UTC_TIMESTAMP() AND r.notice_deadline < (UTC_TIMESTAMP() + INTERVAL 24 HOUR)",
                'r.notice_deadline ASC',
            ))->fetchAll(),
            'alerts' => $includeAdmin ? $this->pdo->query(
                "SELECT u.uuid, u.full_name, ct.consecutive_miss_count, ct.period_month
                   FROM compliance_tracking ct JOIN users u ON u.id = ct.inspector_id
                  WHERE ct.alert_triggered = 1 AND ct.period_month = (SELECT MAX(period_month) FROM compliance_tracking)
               ORDER BY ct.consecutive_miss_count DESC LIMIT {$limit}"
            )->fetchAll() : [],
        ];
    }

    private function complianceAlerts(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM compliance_tracking
              WHERE alert_triggered = 1 AND period_month = (SELECT MAX(period_month) FROM compliance_tracking)'
        )->fetchColumn();
    }
}
