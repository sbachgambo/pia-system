<?php

declare(strict_types=1);

namespace App\Mail;

use Psr\Log\LoggerInterface;

/** Used when MAIL_HOST is empty: nothing is sent, the attempt is only logged. */
final class NullMailer implements MailerInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'Not configured — set MAIL_HOST and the other MAIL_* values in .env to turn email on.';
    }

    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        // Subject only — bodies can carry one-time links, so they are never logged.
        $this->logger->info('Email not sent (MAIL_HOST is not configured)', ['subject' => $subject]);
    }
}
