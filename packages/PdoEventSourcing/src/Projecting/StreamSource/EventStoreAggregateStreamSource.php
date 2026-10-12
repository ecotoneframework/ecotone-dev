<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use function count;

use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Projecting\StreamFilter;
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

        $parts = explode(':', $partitionKey, 3);
        Assert::isTrue(count($parts) === 3, "Partition key must be in format 'streamName:aggregateType:aggregateId', got: {$partitionKey}");

        [$streamName, $aggregateType, $aggregateId] = $parts;
        $streamFilter = null;
        foreach ($streamFilters as $filter) {
            if ($filter->streamName === $streamName && $filter->aggregateType === $aggregateType) {
                $streamFilter = $filter;
                break;
            }
        }
        Assert::notNull($streamFilter, "No matching stream filter for: {$streamName}:{$aggregateType}");

        return $this->loadFromStreamFilter($streamFilter, $aggregateId, $lastPosition, $count);
    }

    private function loadFromStreamFilter(StreamFilter $streamFilter, string $aggregateId, ?string $lastPosition, int $count): StreamPage
    {
        $events = $this->eventStore->loadAggregateEvents(
            $streamFilter->streamName,
            $streamFilter->aggregateType,
            $aggregateId,
            (int) $lastPosition + 1,
            $count,
            $streamFilter->eventNames,
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
