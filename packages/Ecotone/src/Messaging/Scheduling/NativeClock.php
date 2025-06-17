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
class NativeClock implements Clock
{
    use ClockTrait;

    /**
     * @inheritDoc
     */
    public function timestamp(): Timestamp
    {
        return Timestamp::fromTimestamp(microtime(true));
    }

    public function usleep(int $microseconds): void
    {
        usleep($microseconds);
    }

    /**
     * @inheritDoc
     */
    public function now(): DateTimeImmutable
    {
        return $this->timestamp()->toDateTime();
    }
}
