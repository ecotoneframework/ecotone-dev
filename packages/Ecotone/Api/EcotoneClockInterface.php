<?php

declare(strict_types=1);

namespace Ecotone\Api;

use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\SleepInterface;
use Psr\Clock\ClockInterface as PsrClockInterface;

/**
 * Interface Clock
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface EcotoneClockInterface extends PsrClockInterface, SleepInterface
{
    public function now(): DatePoint;
}
