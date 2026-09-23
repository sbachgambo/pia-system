<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mail\MailerInterface;
use App\Mail\MailException;

/** Test double: records messages instead of sending them. Not named *Test. */
final class CollectingMailer implements MailerInterface
{
    /** @var list<array{to:string,name:string,subject:string,body:string}> */
    public array $sent = [];

    public function __construct(private readonly bool $enabled = true, private readonly bool $failing = false)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function describe(): string
    {
        return 'test double';
    }

    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        if ($this->failing) {
            throw new MailException('SMTP refused the connection');
        }
        $this->sent[] = ['to' => $toEmail, 'name' => $toName, 'subject' => $subject, 'body' => $textBody];
    }
}
