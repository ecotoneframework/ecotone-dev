<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use Ecotone\Projecting\Tracking\SequenceFactory;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;

class EventStoreStreamSource implements StreamSource
{
    public function __construct(
        private EventStore      $eventStore,
        private string          $streamName,
        private SequenceFactory $sequenceAccessorFactory,
    ) {
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        $metadataMatcher = null;
        if ($partitionKey) {
            $metadataMatcher = (new MetadataMatcher())->withMetadataMatch(
                MessageHeaders::EVENT_AGGREGATE_ID,
                Operator::EQUALS(),
                $partitionKey
            );
        }
        $events = $this->eventStore->load(
            $this->streamName,
            $lastPosition ? (int)$lastPosition + 1 : 1,
            $count,
            $metadataMatcher,
        );

        return new StreamPage($events, $this->sequenceAccessorFactory->createPositionFrom($lastPosition, $events));
    }
}