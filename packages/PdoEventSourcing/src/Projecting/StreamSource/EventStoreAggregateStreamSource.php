<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use function count;

use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\FieldType;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Projecting\StreamFilterRegistry;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;

use function in_array;

use RuntimeException;

class EventStoreAggregateStreamSource implements StreamSource
{
    /**
     * @param string[] $handledProjectionNames
     */
    public function __construct(
        private EventStore $eventStore,
        private StreamFilterRegistry $streamFilterRegistry,
        private array $handledProjectionNames,
    ) {
    }

    public function canHandle(string $projectionName): bool
    {
        return in_array($projectionName, $this->handledProjectionNames, true);
    }

    public function load(string $projectionName, ?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        Assert::notNull($partitionKey, 'Partition key cannot be null for aggregate stream source');

        $streamFilters = $this->streamFilterRegistry->provide($projectionName);
        Assert::isTrue(count($streamFilters) > 0, "No stream filter found for projection: {$projectionName}");
        $streamFilter = $streamFilters[0];

        if (! $this->eventStore->hasStream($streamFilter->streamName)) {
            return new StreamPage([], $lastPosition ?? '');
        }

        $metadataMatcher = new MetadataMatcher();
        if ($streamFilter->aggregateType !== null) {
            $metadataMatcher = $metadataMatcher->withMetadataMatch(
                MessageHeaders::EVENT_AGGREGATE_TYPE,
                Operator::EQUALS,
                $streamFilter->aggregateType
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

        if ($streamFilter->eventNames !== []) {
            $metadataMatcher = $metadataMatcher->withMetadataMatch(
                'event_name',
                Operator::IN,
                $streamFilter->eventNames,
                FieldType::MESSAGE_PROPERTY
            );
        }

        $events = $this->eventStore->load(
            $streamFilter->streamName,
            1,
            $count,
            $metadataMatcher,
        );

        return new StreamPage($events, $this->createPositionFrom($lastPosition, $events));
    }

    /**
     * @param array<mixed> $events
     */
    private function createPositionFrom(?string $lastPosition, array $events): string
    {
        $lastEvent = end($events);
        if ($lastEvent === false) {
            return $lastPosition ?? '';
        }
        return (string) $lastEvent->getMetadata()[MessageHeaders::EVENT_AGGREGATE_VERSION] ?? throw new RuntimeException('Last event does not have aggregate version');
    }
}
