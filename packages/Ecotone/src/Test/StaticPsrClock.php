<?php

declare(strict_types=1);

namespace Ecotone\Test;

use DateTimeImmutable;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\SleepInterface;
use Psr\Clock\ClockInterface;

/**
 * licence Apache-2.0
 */
final class StaticPsrClock implements ClockInterface, SleepInterface
{
    private DateTimeImmutable $currentTime;
    private bool $hasBeenChanged = false;

    public function __construct(?string $now = null)
    {
        $this->currentTime = $now === null ? new DateTimeImmutable() : new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function sleep(Duration $duration): void
    {
        if ($duration->isNegativeOrZero()) {
            return;
        }

        $this->currentTime = $this->currentTime->modify("+{$duration->inMicroseconds()} microseconds");
    }

    public function hasBeenChanged(): bool
    {
        return $this->hasBeenChanged;
    }

    public function setCurrentTime(DateTimeImmutable $time): void
    {
        $this->currentTime = $time;
        $this->hasBeenChanged = true;
    }
}
