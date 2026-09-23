<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\MailerInterface;
use App\Mail\MailException;
use PDO;

/**
 * The daily "needs attention" email: the same live list as the console bell
 * (AttentionFeed), sent to active office staff who haven't opted out. Nobody is
 * emailed on a quiet day — an empty digest is noise that trains people to
 * ignore the real ones.
 */
final class DigestService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AttentionFeed $feed,
        private readonly MailerInterface $mailer,
        private readonly string $baseUrl,
        private readonly string $appName,
    ) {
    }

    /** @return array{enabled:bool,sent:int,skipped:int,failed:int} */
    public function send(): array
    {
        if (!$this->mailer->isEnabled()) {
            return ['enabled' => false, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $recipients = $this->pdo->query(
            "SELECT email, full_name, role FROM users
              WHERE status = 'active' AND receive_digest = 1 AND role IN ('office_reviewer', 'admin', 'super_admin')
           ORDER BY id"
        )->fetchAll();

        $countsFor = [];
        $sent = $skipped = $failed = 0;

        foreach ($recipients as $r) {
            $isAdmin = in_array($r['role'], ['admin', 'super_admin'], true);
            $c = $countsFor[(int) $isAdmin] ??= $this->feed->counts($isAdmin);

            if ($c['total'] === 0) {
                $skipped++;
                continue;
            }

            try {
                $this->mailer->send((string) $r['email'], (string) $r['full_name'], $this->subject($c['total']), $this->body((string) $r['full_name'], $c, $isAdmin));
                $sent++;
            } catch (MailException) {
                $failed++;
            }
        }

        return ['enabled' => true, 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function subject(int $total): string
    {
        return "{$this->appName}: {$total} " . ($total === 1 ? 'item needs' : 'items need') . ' attention';
    }

    /** @param array{review:int,overdue:int,due_soon:int,alerts:int,total:int} $c */
    private function body(string $name, array $c, bool $isAdmin): string
    {
        $lines = ["Hello {$name},", '', 'Here is what is waiting on the team right now:', ''];
        if ($c['review'] > 0) {
            $lines[] = "  - {$c['review']} inspection(s) awaiting review";
        }
        if ($c['overdue'] > 0) {
            $lines[] = "  - {$c['overdue']} inspection request(s) past their notice deadline";
        }
        if ($c['due_soon'] > 0) {
            $lines[] = "  - {$c['due_soon']} unscheduled request(s) due within 24 hours";
        }
        if ($isAdmin && $c['alerts'] > 0) {
            $lines[] = "  - {$c['alerts']} inspector(s) on a compliance alert";
        }
        $lines[] = '';
        $lines[] = 'Details: ' . rtrim($this->baseUrl, '/') . '/console/attention';
        $lines[] = '';
        $lines[] = "You get this because your {$this->appName} account has the daily digest switched on; an admin can turn it off on your user page.";

        return implode("\n", $lines) . "\n";
    }
}
