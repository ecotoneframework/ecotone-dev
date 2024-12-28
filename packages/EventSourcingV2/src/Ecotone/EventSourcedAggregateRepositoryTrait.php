<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourced;

trait EventSourcedAggregateRepositoryTrait
{

    public function canHandle(string $aggregateClassName): bool
    {
        // todo: Just for the POC, should be optimized by Ecotone
        return $this->getEventSourcedAttribute($aggregateClassName) !== null;
    }

    protected function getEventSourcedAttribute(string|object $objectOrClass): ?EventSourced
    {
        $reflectionClass = new \ReflectionClass($objectOrClass);
        $eventSourcedAttributes = $reflectionClass->getAttributes(EventSourced::class);
        if (count($eventSourcedAttributes) === 0) {
            return null;
        }
        return reset($eventSourcedAttributes)->newInstance();
    }

    protected function getStreamId(EventSourced $eventSourcedOptions, array $identifiers)
    {
        return $eventSourcedOptions->name . '-' . implode('-', $identifiers);
    }
}