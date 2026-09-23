<?php

declare(strict_types=1);

namespace App\Tests\Notifications;

use App\Notifications\AttentionFeed;
use App\Notifications\DigestService;
use App\Tests\Support\CollectingMailer;
use App\Tests\Support\DatabaseTestCase;

final class DigestServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['compliance_tracking', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function service(CollectingMailer $mailer): DigestService
    {
        return new DigestService($this->pdo, new AttentionFeed($this->pdo), $mailer, 'https://pia.example.test', 'ADWOL PIA');
    }

    private function makeOverdueRequest(): void
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $cons = $this->makeConsignmentRow($this->makeClientRow()['id']);
        $req = $this->makeInspectionRequestRow($cons['id'], $inspector['id']);
        $this->pdo->exec("UPDATE inspection_requests SET notice_deadline = UTC_TIMESTAMP() - INTERVAL 3 HOUR WHERE id = {$req['id']}");
    }

    public function testSendsOnlyToActiveOfficeStaffWhoHaveNotOptedOutAndOnlyWhenSomethingIsWaiting(): void
    {
        $this->makeOverdueRequest();
        $this->makeUser(['role' => 'office_reviewer', 'email' => 'rev@adwol.test']);
        $this->makeUser(['role' => 'admin', 'email' => 'optout@adwol.test']);
        $this->makeUser(['role' => 'super_admin', 'email' => 'sa@adwol.test', 'status' => 'suspended']);
        $this->pdo->exec("UPDATE users SET receive_digest = 0 WHERE email = 'optout@adwol.test'");
        $mailer = new CollectingMailer();

        $r = $this->service($mailer)->send();

        self::assertSame(['enabled' => true, 'sent' => 1, 'skipped' => 0, 'failed' => 0], $r);
        self::assertSame('rev@adwol.test', $mailer->sent[0]['to']);
        self::assertStringContainsString('1 item needs attention', $mailer->sent[0]['subject']);
        self::assertStringContainsString('1 inspection request(s) past their notice deadline', $mailer->sent[0]['body']);
        self::assertStringContainsString('https://pia.example.test/console/attention', $mailer->sent[0]['body']);
    }

    public function testQuietDayEmailsNobodyAndDisabledMailerDoesNothing(): void
    {
        $this->makeUser(['role' => 'admin', 'email' => 'a@adwol.test']);
        $mailer = new CollectingMailer();

        self::assertSame(['enabled' => true, 'sent' => 0, 'skipped' => 1, 'failed' => 0], $this->service($mailer)->send());
        self::assertSame([], $mailer->sent);
        self::assertFalse($this->service(new CollectingMailer(false))->send()['enabled']);
    }

    public function testAFailingTransportIsCountedNotThrown(): void
    {
        $this->makeOverdueRequest();
        $this->makeUser(['role' => 'admin', 'email' => 'a@adwol.test']);

        self::assertSame(1, $this->service(new CollectingMailer(true, true))->send()['failed']);
    }
}
