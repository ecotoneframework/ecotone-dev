<?php

declare(strict_types=1);

namespace Ecotone\Modelling\EventSourcingExecutor;

use Ecotone\Modelling\Event;

use function implode;
use function sha1;
use function sort;

/**
 * licence Apache-2.0
 */
final class GroupedEventSourcingExecutor
{
    /**
     * @param array<string, EventSourcingHandlerExecutor> $eventSourcingExecutors
     */
    public function __construct(private array $eventSourcingExecutors)
    {

    }

    /**
     * Identifies the fold a snapshot was taken with, so a class that gained or lost an
     * #[EventSourcingHandler] stops matching its own stored snapshots.
     */
    public function foldShapeOf(string $aggregateClassName): string
    {
        $handledEventTypeNames = $this->eventSourcingExecutors[$aggregateClassName]->handledEventTypeNames();
        sort($handledEventTypeNames);

        return sha1($aggregateClassName . "\0" . implode(',', $handledEventTypeNames));
    }

    /**
     * @param Event[] $events
     */
    public function fillFor(string $aggregateClassName, ?object $aggregateInstance, array $events): object
    {
        $eventSourcingExecutor = $this->eventSourcingExecutors[$aggregateClassName];

        return $eventSourcingExecutor->fill($events, $aggregateInstance);
    }
}
