<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Prooph\EventStore\Metadata\MetadataMatcher;

class EventStoreStreamSource implements StreamSource
{
    public function __construct(
        private EventStore      $eventStore,
        private string          $streamName,
        private MetadataMatcher $metadataMatcher,
        private SequenceFactory $sequenceAccessorFactory,
    ) {
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        $events = $this->eventStore->load(
            $this->streamName,
            $lastPosition ? (int)$lastPosition + 1 : 1,
            $count,
            $this->metadataMatcher,
        );

        return new StreamPage($events, $this->sequenceAccessorFactory->createPositionFrom($lastPosition, $events));
    }
}