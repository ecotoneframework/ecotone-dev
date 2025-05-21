<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Ecotone\Projecting\Tracking\SequenceAccessor\GapAwarePosition;

class EventStoreGlobalStreamSource implements StreamSource
{
    public function __construct(
        private EventStore      $eventStore,
        private string          $streamName,
    ) {
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::null($partitionKey, 'Partition key is not supported for EventStoreGlobalStreamSource');
        $tracking = GapAwarePosition::fromString($lastPosition);

        // Dumbly query for all events  in gaps
        $eventsInGaps = [];
        foreach ($tracking->getGaps() as $gap) {
            $eventsInGaps = array_merge($eventsInGaps, $this->eventStore->load(
                $this->streamName,
                $gap,
                1,
            ));
        }

        $events = $this->eventStore->load(
            $this->streamName,
            $tracking->getPosition() + 1,
            $count,
        );


        $allEvents = [...$eventsInGaps, ...$events];

        foreach ($allEvents as $event) {
            $position = $event->getMetadata()['_position'] ?? throw new \RuntimeException('Event does not have a position');

            $tracking->advanceTo((int) $position);
        }

        return new StreamPage($allEvents, (string) $tracking);
    }
}