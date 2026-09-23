<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\EventStore\AggregateEventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\Event;

/**
 * licence Apache-2.0
 */
final class SerializingEventStore implements EventStore, AggregateEventStore
{
    public function __construct(
        private EventStore $eventStore,
        private EventSerializer $eventSerializer,
    ) {
    }

    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void
    {
        $this->eventStore->create($streamName, $this->serialize($streamEvents), $streamMetadata);
    }

    public function appendTo(string $streamName, array $streamEvents): void
    {
        $this->eventStore->appendTo($streamName, $this->serialize($streamEvents));
    }

    public function delete(string $streamName): void
    {
        $this->eventStore->delete($streamName);
    }

    public function hasStream(string $streamName): bool
    {
        return $this->eventStore->hasStream($streamName);
    }

    public function load(
        string $streamName,
        int $fromNumber = 1,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null,
        bool $deserialize = true
    ): iterable {
        $events = [];
        foreach ($this->eventStore->load($streamName, $fromNumber, $count, $metadataMatcher, $deserialize) as $event) {
            $events[] = $this->eventSerializer->deserialize(
                $event->getEventName(),
                $event->getPayload(),
                $event->getMetadata(),
                $deserialize
            );
        }

        return $events;
    }

    /**
     * @param string[] $eventNames
     */
    public function loadAggregateEvents(
        string $streamName,
        ?string $aggregateType,
        string $aggregateId,
        int $fromVersion = 1,
        ?int $count = null,
        array $eventNames = [],
        bool $deserialize = true
    ): iterable {
        if (! $this->eventStore instanceof AggregateEventStore) {
            throw new InvalidArgumentException(sprintf('%s does not implement %s', $this->eventStore::class, AggregateEventStore::class));
        }

        $events = [];
        foreach ($this->eventStore->loadAggregateEvents($streamName, $aggregateType, $aggregateId, $fromVersion, $count, $eventNames, $deserialize) as $event) {
            $events[] = $this->eventSerializer->deserialize(
                $event->getEventName(),
                $event->getPayload(),
                $event->getMetadata(),
                $deserialize
            );
        }

        return $events;
    }

    /**
     * @param Event[]|object[]|array[] $streamEvents
     * @return Event[]
     */
    private function serialize(array $streamEvents): array
    {
        return array_map(fn (object|array $event) => $this->eventSerializer->serialize($event), $streamEvents);
    }
}
