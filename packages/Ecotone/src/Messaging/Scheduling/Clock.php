<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

use Psr\Clock\ClockInterface;

/**
 * Interface Clock
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface Clock extends ClockInterface
{
    public function timestamp(): Timestamp;

    public function sleep(float|int|Duration $secondsOrDuration): void;
    public function usleep(int $microseconds): void;
}
