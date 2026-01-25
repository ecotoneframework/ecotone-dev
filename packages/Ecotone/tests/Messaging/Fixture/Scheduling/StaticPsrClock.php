<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Scheduling;

use DateTimeImmutable;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\SleepInterface;
use Psr\Clock\ClockInterface;

/**
 * licence Apache-2.0
 */
final class StaticPsrClock implements ClockInterface, SleepInterface
{
    private static DateTimeImmutable $now;

    public function __construct(string $now)
    {
        self::$now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return self::$now;
    }

    public function sleep(Duration $duration): void
    {
        self::$now = self::$now->modify("+{$duration->zeroIfNegative()->inMicroseconds()} microseconds");
    }
}
