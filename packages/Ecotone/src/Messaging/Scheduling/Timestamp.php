<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

use DateTimeImmutable;

class Timestamp
{
    public function __construct(
        private readonly Duration $durationSinceEpoch,
    ) {
        // We allow negative timestamps to represent times before the Unix epoch (1970-01-01T00:00:00Z).
    }

    public static function fromDateTime(\DateTimeInterface $dateTime): self
    {
        // Convert the DateTime to microseconds since epoch
        $microseconds = (int)($dateTime->getTimestamp() * 1_000_000 + ($dateTime->format('u') ?? 0));

        return new self(Duration::microseconds($microseconds));
    }

    public static function fromString(string $dateTime): self
    {
        $dateTimeObject = new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC'));

        return self::fromDateTime($dateTimeObject);
    }

    public static function fromMicrotime(int|float $microseconds): self
    {
        return new self(Duration::microseconds($microseconds));
    }

    public static function fromMilliseconds(int|float $milliseconds): self
    {
        return new self(Duration::milliseconds($milliseconds));
    }

    public static function fromTimestamp(int|float $seconds): self
    {
        return new self(Duration::seconds($seconds));
    }

    public function toMicroseconds(): int
    {
        return $this->durationSinceEpoch->toMicroseconds();
    }

    public function toMilliseconds(): int
    {
        return $this->durationSinceEpoch->toMilliseconds();
    }

    /**
     * @return float the timestamp in seconds since the Unix epoch (1970-01-01T00:00:00Z).
     */
    public function toFloat(): float
    {
        return $this->durationSinceEpoch->toFloat();
    }

    public function toSeconds(): int
    {
        return $this->durationSinceEpoch->toSeconds();
    }

    /**
     * @return \DateTimeImmutable the datetime representation of this timestamp in UTC timezone.
     */
    public function toDateTime(): \DateTimeImmutable
    {
        if (\PHP_VERSION_ID >= 80400) {
            return DateTimeImmutable::createFromTimestamp($this->toFloat());
        } else {
            return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->toFloat()), new \DateTimeZone('UTC'));
        }
    }

    public function add(Duration $duration): self
    {
        return new self($this->durationSinceEpoch->add($duration));
    }

    public function subtract(Duration $duration): self
    {
        return new self($this->durationSinceEpoch->subtract($duration));
    }

    public function equals(mixed $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }
        return $this->durationSinceEpoch === $other->durationSinceEpoch;
    }

    public function diff(self $other): Duration
    {
        return $this->durationSinceEpoch->diff($other->durationSinceEpoch);
    }

    public function isBefore(self $other): bool
    {
        return $this->diff($other)->isNegative();
    }

    public function isBeforeOrEqual(self $other): bool
    {
        return $this->diff($other)->isNegativeOrZero();
    }

    public function isAfter(self $other): bool
    {
        return $this->diff($other)->isPositive();
    }

    public function isAfterOrEqual(self $other): bool
    {
        return $this->diff($other)->isPositiveOrZero();
    }

    public function __toString(): string
    {
        return (string)$this->durationSinceEpoch;
    }
}