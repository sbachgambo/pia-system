<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/** A message could not be handed to the mail transport. */
final class MailException extends RuntimeException
{
}
