<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;
use RuntimeException;

class EventStoreAggregateStreamSource implements StreamSource
{
    public function __construct(
        private EventStore      $eventStore,
        private string          $streamName,
        private ?string         $aggregateType,
    ) {
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::notNull($partitionKey, 'Partition key cannot be null for aggregate stream source');

        $metadataMatcher = new MetadataMatcher();
        if ($this->aggregateType !== null) {
            // @todo: watch out ! Prooph's event store has an index on (aggregate_type, aggregate_id). Not adding aggregate type here will result in a full table scan
            $metadataMatcher = $metadataMatcher->withMetadataMatch(
                MessageHeaders::EVENT_AGGREGATE_TYPE,
                Operator::EQUALS,
                $this->aggregateType
            );
        }
        $metadataMatcher = $metadataMatcher->withMetadataMatch(
            MessageHeaders::EVENT_AGGREGATE_ID,
            Operator::EQUALS,
            $partitionKey
        );
        $metadataMatcher = $metadataMatcher->withMetadataMatch(
            MessageHeaders::EVENT_AGGREGATE_VERSION,
            Operator::GREATER_THAN_EQUALS,
            (int)$lastPosition + 1
        );

        $events = $this->eventStore->load(
            $this->streamName,
            1,
            $count,
            $metadataMatcher,
        );

        return new StreamPage($events, $this->createPositionFrom($lastPosition, $events));
    }

    private function createPositionFrom(?string $lastPosition, array $events): ?string
    {
        $lastEvent = end($events);
        if ($lastEvent === false) {
            return $lastPosition;
        }
        return (string) $lastEvent->getMetadata()[MessageHeaders::EVENT_AGGREGATE_VERSION] ?? throw new RuntimeException('Last event does not have aggregate version');
    }
}
