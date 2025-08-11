<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging;

use DateTimeImmutable;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\SleepInterface;
use Psr\Clock\ClockInterface;

final class StaticClock implements ClockInterface, SleepInterface
{
    public static DateTimeImmutable $now;
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
        self::$now = self::$now->modify("+{$duration->inMicroseconds()} microseconds");
    }
}
