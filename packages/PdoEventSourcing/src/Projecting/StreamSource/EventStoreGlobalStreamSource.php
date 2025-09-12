<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Prooph\EventStore\Metadata\FieldType;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;

class EventStoreGlobalStreamSource implements StreamSource
{
    public function __construct(
        private EventStore      $eventStore,
        private string          $streamName,
        private int             $maxGapOffset = 5_000,
    ) {
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::null($partitionKey, 'Partition key is not supported for EventStoreGlobalStreamSource');
        $tracking = GapAwarePosition::fromString($lastPosition);

        if (count($tracking->getGaps()) === 0) {
            $eventsInGaps = [];
        } else {
            $eventsInGaps = $this->eventStore->load(
                $this->streamName,
                metadataMatcher: (new MetadataMatcher())
                    ->withMetadataMatch('no', Operator::IN(), $tracking->getGaps(), FieldType::MESSAGE_PROPERTY())
            );
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

        $tracking->cleanByMaxOffset($this->maxGapOffset);

        return new StreamPage($allEvents, (string) $tracking);
    }
}