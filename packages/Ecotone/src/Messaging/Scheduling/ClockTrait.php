<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

trait ClockTrait
{
    public function sleep(float|int|Duration $secondsOrDuration): void
    {
        if (!$secondsOrDuration instanceof Duration) {
            $secondsOrDuration = Duration::seconds($secondsOrDuration);
        }
        if ($secondsOrDuration->isNegativeOrZero()) {
            return;
        }
        $this->usleep($secondsOrDuration->toMicroseconds());
    }
}