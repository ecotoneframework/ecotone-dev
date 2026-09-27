<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\Tag;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\Modelling\Event;

/**
 * licence Apache-2.0
 */
interface InMemoryTagCollaborator
{
    public function loadByCriteria(InMemoryStreamAccess $streamAccess, EventCriteria $criteria): LoadedEvents;

    /**
     * @param Event[] $events
     */
    public function appendEventsWithTagCondition(InMemoryStreamAccess $streamAccess, string $streamName, array $events, ?AppendCondition $appendCondition): void;

    public function deleteTagIndexFor(string $streamName): void;
}