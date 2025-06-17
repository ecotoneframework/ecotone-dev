<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Class StubClock
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class StubUTCClock implements Clock
{
    use ClockTrait;

    public function __construct(
        private Timestamp $currentTime
    ) {
    }

    /**
     * @param string $currentTime
     * @return StubUTCClock
     */
    public static function createWithCurrentTime(string $currentTime): self
    {
        return new self(Timestamp::fromString($currentTime));
    }

    /**
     * @inheritDoc
     */
    public function timestamp(): Timestamp
    {
        return$this->currentTime;
    }

    public function usleep(int $microseconds): void
    {
        $this->currentTime = $this->currentTime->add(Duration::microseconds($microseconds));
    }

    public function changeCurrentTime(string|DateTimeInterface $newCurrentTime): void
    {
        $this->currentTime = self::createTimestamp($newCurrentTime);
    }

    private static function createTimestamp(string|DateTimeInterface $dateTime): Timestamp
    {
        if ($dateTime === 'now') {
            throw new \InvalidArgumentException('Cannot create epoch time in milliseconds from "now" string. Use "now" method instead.');
        }

        $dateTime = is_string($dateTime) ? new DateTime($dateTime, new DateTimeZone('UTC')) : $dateTime;

        return Timestamp::fromDateTime($dateTime);
    }

    public function now(): DateTimeImmutable
    {
        return $this->currentTime->toDateTime();
    }
}
