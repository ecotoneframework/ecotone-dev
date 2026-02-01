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
    private ?DateTimeImmutable $frozenTime = null;
    private bool $hasBeenChanged = false;

    public function __construct(?string $now = null)
    {
        if ($now !== null) {
            $this->frozenTime = new DateTimeImmutable($now);
        }
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozenTime ?? new DateTimeImmutable();
    }

    public function sleep(Duration $duration): void
    {
        if ($duration->isNegativeOrZero()) {
            return;
        }

        $this->frozenTime = $this->now()->modify("+{$duration->inMicroseconds()} microseconds");
        $this->hasBeenChanged = true;
    }

    public function hasBeenChanged(): bool
    {
        return $this->hasBeenChanged;
    }

    public function setCurrentTime(DateTimeImmutable $time): void
    {
        $this->frozenTime = $time;
        $this->hasBeenChanged = true;
    }
}
