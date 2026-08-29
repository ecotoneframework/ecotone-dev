<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\Modelling\Event;

/**
 * licence Apache-2.0
 */
final class SerializingEventStore implements EventStore
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
     * @param Event[]|object[]|array[] $streamEvents
     * @return Event[]
     */
    private function serialize(array $streamEvents): array
    {
        return array_map(fn (object|array $event) => $this->eventSerializer->serialize($event), $streamEvents);
    }
}
