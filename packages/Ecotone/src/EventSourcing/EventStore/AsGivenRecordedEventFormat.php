<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\EventStore;

use Ecotone\Modelling\Event;

use function is_array;

/**
 * licence Apache-2.0
 */
final class AsGivenRecordedEventFormat implements RecordedEventFormat
{
    public function recordedFrom(object|array $event): Event
    {
        if ($event instanceof Event) {
            return $event;
        }

        if (is_array($event)) {
            return Event::createWithType('array', $event);
        }

        return Event::create($event);
    }
}
