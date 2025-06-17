<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Scheduling;

/**
 * Interface Trigger
 * @package Ecotone\Messaging\Scheduling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface Trigger
{
    /**
     * @param Clock $clock
     * @param TriggerContext $triggerContext
     * @return Timestamp
     */
    public function nextExecutionTime(Clock $clock, TriggerContext $triggerContext): Timestamp;
}
