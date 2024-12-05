<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Test;

use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ecotone\EventSourcingV2\EventStore\Subscription\EventLoader;
use Ecotone\EventSourcingV2\EventStore\Subscription\SubscriptionQuery;

class InMemoryEventStore implements EventStore, EventLoader
{
    private int $nextEventId = 1;

    /** @var array<PersistedEvent>  */
    private array $events = [];

    /** @var array<InMemoryStream>  */
    private array $streams = [];

    public function append(StreamEventId $eventStreamId, array $events): array
    {
        $stream = $this->streams[(string) $eventStreamId->streamId] ?? null;
        if ($stream === null) {
            $stream = $this->streams[(string) $eventStreamId->streamId] = new InMemoryStream((string) $eventStreamId->streamId, 0);
        }
        $persistedEvents = [];
        foreach ($events as $event) {
            $persistedEvents[] = $persistedEvent = new PersistedEvent(
                new StreamEventId($eventStreamId->streamId, $stream->version++),
                new LogEventId(1, $this->nextEventId),
                $event,
            );
            $stream->events[] = $this->events[$this->nextEventId] = $persistedEvent;
            $this->nextEventId++;
        }

        return $persistedEvents;
    }

    public function load(StreamEventId $eventStreamId): iterable
    {
        $stream = $this->streams[(string) $eventStreamId->streamId] ?? null;
        if ($stream === null) {
            return [];
        }
        reset($stream->events);
        foreach ($stream->events as $event) {
            if ($eventStreamId->version && $event->streamEventId->version < $eventStreamId->version) {
                continue;
            }
            yield $event;
        }
    }

    public function query(SubscriptionQuery $query): iterable
    {
        foreach ($this->events as $event) {
            if ($query->streamIds && !in_array($event->streamEventId->streamId, $query->streamIds, true)) {
                continue;
            }
            if ($query->from && $event->logEventId->transactionId < $query->from->transactionId) {
                continue;
            }
            if ($query->from && $event->logEventId->transactionId === $query->from->transactionId && $event->logEventId->sequenceNumber <= $query->from->sequenceNumber) {
                continue;
            }
            yield $event;
        }
    }
}

/**
 * @internal
 */
class InMemoryStream {
    /**
     * @param array<PersistedEvent> $events
     */
    public function __construct(
        public string $streamId,
        public int $version,
        public array $events = [],
    ) {
    }
}