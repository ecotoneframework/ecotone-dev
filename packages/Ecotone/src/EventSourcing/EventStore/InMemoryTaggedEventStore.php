<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\Api\EventSourcing\TaggedEventStore;

/**
 * licence Enterprise
 */
final class InMemoryTaggedEventStore implements TaggedEventStore
{
    public function __construct(
        private readonly InMemoryEventStore $inMemoryEventStore,
    ) {
    }

    public function load(EventCriteria ...$criteria): LoadedEvents
    {
        return $this->inMemoryEventStore->loadByCriteria(...$criteria);
    }

    public function appendTo(string $streamName, array $events, ?AppendCondition $appendCondition = null): void
    {
        $this->inMemoryEventStore->appendTo($streamName, $events, $appendCondition);
    }
}
