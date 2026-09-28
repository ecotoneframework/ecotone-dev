<?php

declare(strict_types=1);

namespace Ecotone\Modelling;

use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionResolver;
use Ecotone\Modelling\DecisionModel\DecisionModelLoadedState;

/**
 * licence Apache-2.0
 */
final class EventStoreEventSourcedRepository implements EventSourcedRepository
{
    use MatchesEventSourcedAggregateTypes;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly ?array $aggregateTypes = [],
    ) {
    }

    /**
     * @inheritDoc
     */
    public function findBy(string $aggregateClassName, array $identifiers, int $fromVersion = 1): EventStream
    {
        $aggregateId = reset($identifiers);

        $metadataMatcher = (new MetadataMatcher())
            ->withMetadataMatch(MessageHeaders::EVENT_AGGREGATE_TYPE, Operator::EQUALS, $aggregateClassName)
            ->withMetadataMatch(MessageHeaders::EVENT_AGGREGATE_ID, Operator::EQUALS, $aggregateId);

        if ($fromVersion > 0) {
            $metadataMatcher = $metadataMatcher->withMetadataMatch(MessageHeaders::EVENT_AGGREGATE_VERSION, Operator::GREATER_THAN_EQUALS, $fromVersion);
        }

        $streamEvents = $this->eventStore->load(AggregateDefinitionResolver::DEFAULT_STREAM, 1, null, $metadataMatcher);

        if ($streamEvents === []) {
            return EventStream::createEmpty();
        }

        return EventStream::createWith(
            $streamEvents[array_key_last($streamEvents)]->getMetadata()[MessageHeaders::EVENT_AGGREGATE_VERSION],
            $streamEvents,
        );
    }

    /**
     * @inheritDoc
     */
    public function save(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
    {
        $aggregateId = (string) reset($identifiers);
        $appendCondition = AppendCondition::forAggregate($aggregateClassName, $aggregateId, $versionBeforeHandling)
            ->mergeWith(DecisionModelLoadedState::appendConditionIn($metadata));

        $this->eventStore->appendTo(AggregateDefinitionResolver::DEFAULT_STREAM, $events, $appendCondition);
    }
}
