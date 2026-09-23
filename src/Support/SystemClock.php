<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Minimal PSR-20 clock fixed to UTC. Used by the JWT validator's time
 * constraints (and available anywhere a ClockInterface is wanted). Avoids
 * pulling in lcobucci/clock for one small class.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
