<?php

namespace Ecotone\EventSourcing;

use Ecotone\Modelling\Event;

/**
 * licence Apache-2.0
 */
interface ProjectionExecutor
{
    /**
     * @return array|null new generated state
     */
    public function executeWith(string $eventName, Event $event, ?array $state = null): ?array;
}
