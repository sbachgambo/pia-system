<?php

declare(strict_types=1);

namespace App\Mail;

use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

/** SMTP transport via PHPMailer (pure PHP — no sendmail binary needed). */
final class SmtpMailer implements MailerInterface
{
    /** @param array{host:string,port:int,encryption:string,username:string,password:string,from_address:string,from_name:string} $config */
    public function __construct(
        private readonly array $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function describe(): string
    {
        $from = $this->config['from_address'] !== '' ? $this->config['from_address'] : $this->config['username'];

        return sprintf('SMTP %s:%d (%s), sending as %s', $this->config['host'], $this->config['port'], $this->config['encryption'], $from ?: '(no from address set)');
    }

    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->Port = $this->config['port'];
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = 15;

            match ($this->config['encryption']) {
                'ssl'   => $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS,
                'none'  => $mail->SMTPAutoTLS = false,
                default => $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS,
            };

            if ($this->config['username'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $this->config['username'];
                $mail->Password = $this->config['password'];
            }

            $from = $this->config['from_address'] !== '' ? $this->config['from_address'] : $this->config['username'];
            $mail->setFrom($from, $this->config['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(false);
            $mail->Body = $textBody;

            $mail->send();
        } catch (PhpMailerException $e) {
            // ErrorInfo is PHPMailer's own summary (never the password); log it,
            // surface a plain message.
            $this->logger->error('Mail send failed', ['to_domain' => substr(strrchr($toEmail, '@') ?: '', 1), 'error' => $mail->ErrorInfo]);
            throw new MailException('The email could not be sent: ' . $mail->ErrorInfo, previous: $e);
        }
    }
}
