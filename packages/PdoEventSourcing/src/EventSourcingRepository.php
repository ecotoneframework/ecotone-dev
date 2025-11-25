<?php

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\EventSourcing\Prooph\EcotoneEventStoreProophWrapper;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Store\Document\DocumentStore;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\EventSourcedRepository;
use Ecotone\Modelling\EventStream;
use Prooph\EventStore\Exception\StreamNotFound;
use Prooph\EventStore\StreamName;

/**
 * licence Apache-2.0
 */
class EventSourcingRepository implements EventSourcedRepository
{
    /**
     * @param array<string, DocumentStore> $documentStoreReferences
     */
    public function __construct(
        private EcotoneEventStoreProophWrapper $eventStore,
        private array $handledAggregateClassNames,
        private EventSourcingConfiguration $eventSourcingConfiguration,
        private AggregateStreamMapping $aggregateStreamMapping,
        private AggregateTypeMapping $aggregateTypeMapping,
    ) {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return in_array($aggregateClassName, $this->handledAggregateClassNames);
    }

    public function findBy(string $aggregateClassName, array $identifiers, int $fromAggregateVersion = 1): EventStream
    {
        $aggregateId = reset($identifiers);
        $aggregateVersion = $fromAggregateVersion;
        $streamName = $this->getStreamName($aggregateClassName, $aggregateId);
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

        try {
            $streamEvents = $this->eventStore->load($streamName, 1, null, $metadataMatcher);
        } catch (StreamNotFound) {
            return EventStream::createEmpty();
        }

        if (! empty($streamEvents)) {
            $aggregateVersion = $streamEvents[array_key_last($streamEvents)]->getMetadata()[LazyProophEventStore::AGGREGATE_VERSION];
        }

        return EventStream::createWith($aggregateVersion, $streamEvents);
    }

    public function save(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
    {
        $aggregateId = reset($identifiers);
        Assert::notNullAndEmpty($aggregateId, sprintf('There was a problem when retrieving identifier for %s', $aggregateClassName));

        $streamName = $this->getStreamName($aggregateClassName, $aggregateId);

        $this->eventStore->appendTo($streamName, $events);
    }

    private function getStreamName(string $aggregateClassName, mixed $aggregateId): StreamName
    {
        $streamName = $aggregateClassName;
        if (array_key_exists($aggregateClassName, $this->aggregateStreamMapping->getAggregateToStreamMapping())) {
            $streamName =  $this->aggregateStreamMapping->getAggregateToStreamMapping()[$aggregateClassName];
        }

        if ($this->eventSourcingConfiguration->isUsingAggregateStreamStrategyFor($streamName)) {
            $streamName = $streamName . '-' . $aggregateId;
        }

        return new StreamName($streamName);
    }

    private function getAggregateType(string $aggregateClassName): string
    {
        if (array_key_exists($aggregateClassName, $this->aggregateTypeMapping->getAggregateTypeMapping())) {
            return $this->aggregateTypeMapping->getAggregateTypeMapping()[$aggregateClassName];
        }

        return $aggregateClassName;
    }
}
