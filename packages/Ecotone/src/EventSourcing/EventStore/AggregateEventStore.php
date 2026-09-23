<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore;

/**
 * licence Apache-2.0
 */
interface AggregateEventStore
{
    /**
     * @param string[] $eventNames
     * @return \Ecotone\Modelling\Event[]
     */
    public function loadAggregateEvents(
        string $streamName,
        ?string $aggregateType,
        string $aggregateId,
        int $fromVersion = 1,
        ?int $count = null,
        array $eventNames = [],
        bool $deserialize = true
    ): iterable;
}
