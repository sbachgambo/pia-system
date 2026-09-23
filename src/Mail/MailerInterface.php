<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Outgoing email, kept behind an interface so the features that need it
 * (password reset, the daily digest) work — and are testable — whether or not
 * an SMTP server is configured.
 */
interface MailerInterface
{
    /** True only when a real transport is configured; features gate themselves on this. */
    public function isEnabled(): bool;

    /** Human-readable transport summary for the Settings page (never includes credentials). */
    public function describe(): string;

    /**
     * Plain-text email. Throws MailException if it could not be handed to the
     * transport (bad credentials, refused connection…).
     */
    public function send(string $toEmail, string $toName, string $subject, string $textBody): void;
}
