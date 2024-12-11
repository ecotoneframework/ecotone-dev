<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone\StandaloneAggregate;

use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourced;
use Ecotone\EventSourcingV2\Ecotone\Attribute\MutatingEvents;

/**
 * @internal
 */
class EventStream
{
    public function __construct(
        private EventSourced $eventSourced,
        private array $events,
    ) {
    }

    #[MutatingEvents]
    public function events(): array
    {
        return $this->events;
    }

    public function eventSourcedAttribute(): EventSourced
    {
        return $this->eventSourced;
    }
}