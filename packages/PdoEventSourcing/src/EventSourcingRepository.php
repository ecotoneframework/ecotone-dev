<?php

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\EventSourcedRepository;
use Ecotone\Modelling\EventStream;

/**
 * licence Apache-2.0
 */
class EventSourcingRepository implements EventSourcedRepository
{
    public function __construct(
        private EventStore $eventStore,
        private array $handledAggregateClassNames,
        private AggregateStreamMapping $aggregateStreamMapping,
        private AggregateTypeMapping $aggregateTypeMapping,
    ) {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return in_array($aggregateClassName, $this->handledAggregateClassNames);
    }

    public function findBy(string $aggregateClassName, array $identifiers, int $fromVersion = 1): EventStream
    {
        $aggregateId = reset($identifiers);
        $aggregateVersion = $fromVersion;
        $streamName = $this->getStreamName($aggregateClassName);
        $aggregateType = $this->getAggregateType($aggregateClassName);

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch(
            MessageHeaders::EVENT_AGGREGATE_TYPE,
            Operator::EQUALS,
            $aggregateType
        );
        $metadataMatcher = $metadataMatcher->withMetadataMatch(
            MessageHeaders::EVENT_AGGREGATE_ID,
            Operator::EQUALS,
            $aggregateId
        );

        if ($aggregateVersion > 0) {
            $metadataMatcher = $metadataMatcher->withMetadataMatch(
                MessageHeaders::EVENT_AGGREGATE_VERSION,
                Operator::GREATER_THAN_EQUALS,
                $aggregateVersion
            );
        }

        $streamEvents = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        if (! empty($streamEvents)) {
            $aggregateVersion = $streamEvents[array_key_last($streamEvents)]->getMetadata()[MessageHeaders::EVENT_AGGREGATE_VERSION];
        }

        return EventStream::createWith($aggregateVersion, $streamEvents);
    }

    public function save(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
    {
        $aggregateId = reset($identifiers);
        Assert::notNullAndEmpty($aggregateId, sprintf('There was a problem when retrieving identifier for %s', $aggregateClassName));

        $this->eventStore->appendTo($this->getStreamName($aggregateClassName), $events);
    }

    private function getStreamName(string $aggregateClassName): string
    {
        return $this->aggregateStreamMapping->getAggregateToStreamMapping()[$aggregateClassName] ?? StreamTableRegistry::DEFAULT_STREAM;
    }

    private function getAggregateType(string $aggregateClassName): string
    {
        if (array_key_exists($aggregateClassName, $this->aggregateTypeMapping->getAggregateTypeMapping())) {
            return $this->aggregateTypeMapping->getAggregateTypeMapping()[$aggregateClassName];
        }

        return $aggregateClassName;
    }
}
