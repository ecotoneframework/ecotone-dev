<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Api\Scheduling;

use DateInterval;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Support\Assert;

final class Duration implements DefinedObject
{
    private readonly int $microseconds;

    public function __construct(
        int $milliseconds = 0,
        int $seconds = 0,
        int $minutes = 0,
        int $hours = 0,
        int $days = 0,
        int $microseconds = 0,
    ) {
        $this->microseconds = $microseconds
            + $milliseconds * 1_000
            + $seconds * 1_000_000
            + $minutes * 60 * 1_000_000
            + $hours * 60 * 60 * 1_000_000
            + $days * 24 * 60 * 60 * 1_000_000;
    }

    public static function fromDateInterval(DateInterval $dateInterval): self
    {
        Assert::isTrue(
            $dateInterval->y === 0 && $dateInterval->m === 0,
            "Duration can not be created from a DateInterval of {$dateInterval->y} years and {$dateInterval->m} months, as their length depends on the calendar. Pass a DateInterval made of days, hours, minutes and seconds, or build the Duration from those units: new Duration(days: 30)."
        );

        $duration = new self(
            seconds: $dateInterval->s,
            minutes: $dateInterval->i,
            hours: $dateInterval->h,
            days: $dateInterval->d,
            microseconds: (int) round($dateInterval->f * 1_000_000),
        );

        return $dateInterval->invert === 1 ? self::zero()->sub($duration) : $duration;
    }

    public static function microseconds(int $microseconds): self
    {
        return new self(microseconds: $microseconds);
    }

    public static function milliseconds(int|float $milliseconds): self
    {
        return new self(microseconds: (int) round($milliseconds * 1000));
    }

    public static function seconds(int|float $seconds): self
    {
        return new self(microseconds: (int) round($seconds * 1_000_000));
    }

    public static function minutes(int|float $minutes): self
    {
        return new self(microseconds: (int) round($minutes * 60 * 1_000_000));
    }

    public static function hours(int|float $hours): self
    {
        return new self(microseconds: (int) round($hours * 60 * 60 * 1_000_000));
    }

    public static function days(int|float $days): self
    {
        return new self(microseconds: (int) round($days * 24 * 60 * 60 * 1_000_000));
    }

    public static function zero(): self
    {
        return new self();
    }

    public function absolute(): self
    {
        return new self(microseconds: abs($this->microseconds));
    }

    public function inMicroseconds(): int
    {
        return $this->microseconds;
    }

    public function inMilliseconds(): int
    {
        return (int) round($this->microseconds / 1_000);
    }

    public function inSeconds(): int
    {
        return (int) round($this->microseconds / 1_000_000);
    }

    /**
     * Converts the duration to a float representing seconds.
     */
    public function toFloat(): float
    {
        return $this->microseconds / 1_000_000.0;
    }

    public function add(Duration $duration): self
    {
        return new self(microseconds: $this->microseconds + $duration->microseconds);
    }

    public function sub(Duration $duration): self
    {
        return new self(microseconds: $this->microseconds - $duration->microseconds);
    }

    public function diff(self $other): self
    {
        return new self(microseconds: $this->microseconds - $other->microseconds);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->microseconds > $other->microseconds;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        return $this->microseconds >= $other->microseconds;
    }

    public function isLessThan(self $other): bool
    {
        return $this->microseconds < $other->microseconds;
    }

    public function isLessThanOrEqual(self $other): bool
    {
        return $this->microseconds <= $other->microseconds;
    }

    public function isPositive(): bool
    {
        return $this->microseconds > 0;
    }

    public function isPositiveOrZero(): bool
    {
        return $this->microseconds >= 0;
    }

    public function isNegative(): bool
    {
        return $this->microseconds < 0;
    }

    public function isNegativeOrZero(): bool
    {
        return $this->microseconds <= 0;
    }

    public function isZero(): bool
    {
        return $this->microseconds === 0;
    }

    public function zeroIfNegative(): self
    {
        return $this->isNegative() ? self::zero() : $this;
    }

    public function getSecondsPart(): int
    {
        return (int) floor($this->microseconds / 1_000_000);
    }

    public function getMicrosecondsPart(): int
    {
        return $this->microseconds % 1_000_000;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [0, 0, 0, 0, 0, $this->microseconds]);
    }

    public function __toString(): string
    {
        $seconds = $this->getSecondsPart();
        $microseconds = $this->getMicrosecondsPart();

        return sprintf('%d.%06d', $seconds, $microseconds);
    }
}
