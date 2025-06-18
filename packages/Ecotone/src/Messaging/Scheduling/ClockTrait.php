<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

trait ClockTrait
{
    abstract function usleep(int $microseconds): void;

    public function sleep(Duration $secondsOrDuration): void
    {
        if ($secondsOrDuration->isNegativeOrZero()) {
            return;
        }
        $this->usleep($secondsOrDuration->toMicroseconds());
    }
}