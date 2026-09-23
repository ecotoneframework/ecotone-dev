<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

/**
 * licence Enterprise
 */
interface TaggedEventStore
{
    public function load(EventCriteria ...$criteria): LoadedEvents;

    /**
     * @param object[]|array[] $events
     */
    public function appendTo(string $streamName, array $events, ?AppendCondition $appendCondition = null): void;
}
