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
    private Duration $sleepDuration;

    public function __construct(private ?string $now = null)
    {
        $this->sleepDuration = Duration::zero();
    }

    public function now(): DateTimeImmutable
    {
        if ($this->frozenTime !== null) {
            return $this->frozenTime;
        }

        $now = $this->now === null ? new DateTimeImmutable() : new DateTimeImmutable($this->now);

        return $now->modify("+{$this->sleepDuration->zeroIfNegative()->inMicroseconds()} microseconds");
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
