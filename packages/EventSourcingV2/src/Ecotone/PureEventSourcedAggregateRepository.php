<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ecotone\Modelling\Attribute\Repository;
use Ecotone\Modelling\EventSourcedRepository;
use Ecotone\Modelling\EventStream;
use RuntimeException;
#[Repository]
class PureEventSourcedAggregateRepository implements EventSourcedRepository
{
    use EventSourcedAggregateRepositoryTrait;

    public function __construct(
        private EventStore $eventStore,
    ) {
    }

    /**
     * @param class-string $aggregateClassName
     */
    public function findBy(string $aggregateClassName, array $identifiers): EventStream
    {
        $eventSourcedAttribute = $this->getEventSourcedAttribute($aggregateClassName);
        if ($eventSourcedAttribute === null) {
            throw new RuntimeException("Aggregate class $aggregateClassName is not event sourced");
        }

        $streamId = $this->getStreamId($eventSourcedAttribute, $identifiers);
        $persistedEvents = $this->eventStore->load(new StreamEventId($streamId));

        $ecotoneEvents = [];
        $version = 0;
        foreach ($persistedEvents as $persistedEvent) {
            $ecotoneEvents[] = \Ecotone\Modelling\Event::createWithType($persistedEvent->event->type, $persistedEvent->event->data);
            $version = $persistedEvent->streamEventId->version;
        }

        return EventStream::createWith($version, $ecotoneEvents);
    }

    public function save(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
    {
        $eventSourcedAttribute = $this->getEventSourcedAttribute($aggregateClassName);
        if ($eventSourcedAttribute === null) {
            throw new RuntimeException("Aggregate class " . $aggregateClassName . " is not event sourced");
        }

        $streamId = $this->getStreamId($eventSourcedAttribute, $identifiers);
        $eventStoreEvents = [];
        foreach ($events as $ecotoneEvent) {
            if ($ecotoneEvent instanceof \Ecotone\Modelling\Event) {
                $eventStoreEvents[] = new Event($ecotoneEvent->getEventName(), $ecotoneEvent->getPayload());
            } else {
                $eventStoreEvents[] = new Event(\get_class($ecotoneEvent), $ecotoneEvent);
            }
        }
        $this->eventStore->append(new StreamEventId($streamId), $eventStoreEvents);
    }
}