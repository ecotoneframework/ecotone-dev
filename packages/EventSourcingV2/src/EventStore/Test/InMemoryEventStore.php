<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Test;

use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\LogEventId;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Projection\InlineProjectionManager;
use Ecotone\EventSourcingV2\EventStore\Projection\Projector;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ecotone\EventSourcingV2\EventStore\Subscription\EventLoader;
use Ecotone\EventSourcingV2\EventStore\Subscription\EventPage;
use Ecotone\EventSourcingV2\EventStore\Subscription\PersistentSubscriptions;
use Ecotone\EventSourcingV2\EventStore\Subscription\SubscriptionQuery;

class InMemoryEventStore implements EventStore, EventLoader, InlineProjectionManager, PersistentSubscriptions
{
    private int $nextEventId = 1;

    /** @var array<PersistedEvent>  */
    private array $events = [];

    /** @var array<InMemoryStream>  */
    private array $streams = [];

    /**
     * @var array<string>
     */
    private array $projections = [];

    /**
     * @var array<string, SubscriptionQuery>
     */
    private array $subscriptions = [];

    /**
     * @param array<string, Projector> $dynamicProjectors
     * @param array<Projector> $permanentProjectors
     */
    public function __construct(
        private array $dynamicProjectors = [],
        private array $permanentProjectors = [],
    ) {
    }

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

        $this->runProjectionsWith($persistedEvents);

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

    public function runProjectionsWith(array $events): void
    {
        foreach ($this->projections as $projectorName => $state) {
            if ($state === "inline") {
                $projector = $this->dynamicProjectors[$projectorName] ?? null;
                if ($projector === null) {
                    throw new \InvalidArgumentException("Projector not found");
                }
                foreach ($events as $event) {
                    $projector->project($event);
                }
            }
        }
        foreach ($this->permanentProjectors as $projector) {
            foreach ($events as $event) {
                $projector->project($event);
            }
        }
    }

    public function addProjection(string $projectorName, string $state = "catchup"): void
    {
        $this->projections[$projectorName] = $state;
    }

    public function removeProjection(string $projectorName): void
    {
        unset($this->projections[$projectorName]);
    }

    public function catchupProjection(string $projectorName, int $missingEventsMaxLoops = 100): void
    {
        if (!isset($this->projections[$projectorName])) {
            throw new \InvalidArgumentException("Projection not found");
        }
        if ($this->projections[$projectorName] !== "catchup") {
            throw new \InvalidArgumentException("Projection is not in catchup state");
        }
        $projector = $this->dynamicProjectors[$projectorName] ?? null;
        if ($projector === null) {
            throw new \InvalidArgumentException("Projector not found");
        }

        foreach ($this->query(new SubscriptionQuery()) as $event) {
            $projector->project($event);
        }
        $this->projections[$projectorName] = "inline";
    }

    public function createSubscription(string $subscriptionName, SubscriptionQuery $subscriptionQuery): void
    {
        $this->subscriptions[$subscriptionName] = $subscriptionQuery;
    }

    public function deleteSubscription(string $subscriptionName): void
    {
        unset($this->subscriptions[$subscriptionName]);
    }

    public function readFromSubscription(string $subscriptionName): EventPage
    {
        $subscriptionQuery = $this->subscriptions[$subscriptionName] ?? null;
        if ($subscriptionQuery === null) {
            throw new \InvalidArgumentException("Subscription not found");
        }
        /** @var array<PersistedEvent> $persistedEvents */
        $persistedEvents = \iterator_to_array($this->query($subscriptionQuery));
        $lastEvent = end($persistedEvents);
        $firstEvent = reset($persistedEvents);
        return new EventPage(
            $subscriptionName,
            $persistedEvents,
            $firstEvent ? $firstEvent->logEventId : $subscriptionQuery->from,
            $lastEvent ? $lastEvent->logEventId : $subscriptionQuery->from,
            $subscriptionQuery->limit ?? 0,
        );
    }

    public function ack(EventPage $page): void
    {
        $subscriptionQuery = $this->subscriptions[$page->subscriptionName] ?? null;
        if ($subscriptionQuery === null) {
            throw new \InvalidArgumentException("Subscription not found");
        }
        if (!$page->events) {
            return;
        }
        $lastEvent = $page->events[\array_key_last($page->events)];
        $this->subscriptions[$page->subscriptionName] = $subscriptionQuery->withFromPosition($lastEvent->logEventId);
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