<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing;

use Ecotone\Api\EventSourcing\Event;
use Ecotone\EventSourcing\EventStore\RecordedEventFormat;

/**
 * licence Apache-2.0
 */
final class SerializedRecordedEventFormat implements RecordedEventFormat
{
    public function __construct(
        private EventSerializer $eventSerializer,
    ) {
    }

    public function recordedFrom(object|array $event): Event
    {
        return $this->eventSerializer->serialize($event);
    }
}
