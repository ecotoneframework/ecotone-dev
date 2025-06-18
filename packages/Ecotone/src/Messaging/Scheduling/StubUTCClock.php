<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class StubUTCClock implements EcotoneClockInterface
{
    use ClockTrait;

    public function __construct(
        private DatePoint $currentTime
    ) {
    }

    /**
     * @param string $currentTime
     * @return StubUTCClock
     */
    public static function createWithCurrentTime(string $currentTime): self
    {
        return new self(new DatePoint($currentTime, new DateTimeZone('UTC')));
    }

    public function usleep(int $microseconds): void
    {
        $this->currentTime = $this->currentTime->add(Duration::microseconds($microseconds));
    }

    public function changeCurrentTime(string|DateTimeInterface $newCurrentTime): void
    {
        $this->currentTime = self::createTimestamp($newCurrentTime);
    }

    private static function createTimestamp(string|DateTimeInterface $dateTime): DatePoint
    {
        if ($dateTime === 'now') {
            throw new \InvalidArgumentException('Cannot create epoch time in milliseconds from "now" string. Use "now" method instead.');
        }

        $dateTime = is_string($dateTime) ? new DatePoint($dateTime, new DateTimeZone('UTC')) : $dateTime;
        return $dateTime instanceof DatePoint ? $dateTime : DatePoint::createFromInterface($dateTime);
    }

    public function now(): DatePoint
    {
        return $this->currentTime;
    }
}
