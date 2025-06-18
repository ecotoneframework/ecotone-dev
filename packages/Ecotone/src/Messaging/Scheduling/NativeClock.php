<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

use DateTimeImmutable;

/**
 * Class UTCBasedClock
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class NativeClock implements EcotoneClockInterface
{
    use ClockTrait;

    public function usleep(int $microseconds): void
    {
        usleep($microseconds);
    }

    /**
     * @inheritDoc
     */
    public function now(): DatePoint
    {
        return new DatePoint('now');
    }
}
