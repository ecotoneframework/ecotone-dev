<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\Tag;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\Messaging\Support\LicensingException;

/**
 * licence Apache-2.0
 */
final class OpenCoreInMemoryTagCollaborator implements InMemoryTagCollaborator
{
    public function loadByCriteria(InMemoryStreamAccess $streamAccess, EventCriteria $criteria): LoadedEvents
    {
        throw LicensingException::create('Loading events by tag criteria (Dynamic Consistency Boundary) requires Ecotone Enterprise');
    }

    public function appendEventsWithTagCondition(InMemoryStreamAccess $streamAccess, string $streamName, array $events, ?AppendCondition $appendCondition): void
    {
        throw LicensingException::create('Tag-based conditional append (Dynamic Consistency Boundary) requires Ecotone Enterprise');
    }

    public function deleteTagIndexFor(string $streamName): void
    {
    }
}