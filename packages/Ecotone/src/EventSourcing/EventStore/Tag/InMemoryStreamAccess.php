<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore\Tag;

use Ecotone\Modelling\Event;

/**
 * licence Apache-2.0
 */
interface InMemoryStreamAccess
{
    /**
     * @return positive-int the 1-based event number assigned to the appended event
     */
    public function appendEvent(string $streamName, Event $event): int;

    public function eventAt(string $streamName, int $eventNo): ?Event;
}