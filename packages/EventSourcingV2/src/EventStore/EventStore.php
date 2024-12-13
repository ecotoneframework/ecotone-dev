<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore;

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;

interface EventStore
{
    /**
     * @param Event[] $events
     * @return PersistedEvent[]
     */
    public function append(StreamEventId $eventStreamId, array $events): array;

    /**
     * @return iterable<PersistedEvent>
     */
    public function load(StreamEventId $eventStreamId): iterable;
}