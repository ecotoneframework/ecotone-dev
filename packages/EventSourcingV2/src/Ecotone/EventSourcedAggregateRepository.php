<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourced;
use Ecotone\EventSourcingV2\Ecotone\Attribute\MutatingEvents;
use Ecotone\EventSourcingV2\EventStore\Event;
use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\EventSourcingV2\EventStore\StreamEventId;
use Ecotone\Modelling\Attribute\Repository;
use Ecotone\Modelling\StandardRepository;
use RuntimeException;

#[Repository]
class EventSourcedAggregateRepository implements StandardRepository
{
    public function __construct(
        private EventStore $eventStore,
    ) {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        // todo: Just for the POC, should be optimized by Ecotone
        return $this->getEventSourcedAttribute($aggregateClassName) !== null;
    }

    /**
     * @param class-string $aggregateClassName
     */
    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        $eventSourcedAttribute = $this->getEventSourcedAttribute($aggregateClassName);
        if ($eventSourcedAttribute === null) {
            throw new RuntimeException("Aggregate class $aggregateClassName is not event sourced");
        }

        $streamId = $this->getStreamId($eventSourcedAttribute, $identifiers);
        $persistedEvents = $this->eventStore->load(new StreamEventId($streamId));

        $businessEvents = [];
        foreach ($persistedEvents as $persistedEvent) {
            $businessEvents[] = $persistedEvent->event->data;
        }

        return $aggregateClassName::fromEvents($businessEvents);
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $eventSourcedAttribute = $this->getEventSourcedAttribute($aggregate);
        if ($eventSourcedAttribute === null) {
            throw new RuntimeException("Aggregate class " . get_class($aggregate) . " is not event sourced");
        }

        $streamId = $this->getStreamId($eventSourcedAttribute, $identifiers);
        $businessEvents = $this->getMutatingEvents($aggregate);
        $events = [];
        foreach ($businessEvents as $businessEvent) {
            $events[] = new Event(\get_class($businessEvent), $businessEvent);
        }
        $this->eventStore->append(new StreamEventId($streamId), $events);
    }

    private function getEventSourcedAttribute(string|object $objectOrClass): ?EventSourced
    {
        $reflectionClass = new \ReflectionClass($objectOrClass);
        $eventSourcedAttributes = $reflectionClass->getAttributes(EventSourced::class);
        if (count($eventSourcedAttributes) === 0) {
            return null;
        }
        return reset($eventSourcedAttributes)->newInstance();
    }

    private function getMutatingEvents(string|object $objectOrClass): ?array
    {
        $reflectionClass = new \ReflectionClass($objectOrClass);
        foreach ($reflectionClass->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                if ($attribute->getName() === MutatingEvents::class) {
                    $methodName = $method->getName();
                    return $objectOrClass->$methodName();
                }
            }
        }
        return null;
    }

    private function getStreamId(EventSourced $eventSourcedOptions, array $identifiers)
    {
        return $eventSourcedOptions->name . '-' . implode('-', $identifiers);
    }
}