<?php

declare(strict_types=1);

namespace Ecotone\Modelling;

use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingSaga;
use Ecotone\Api\Attribute\Repository;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionResolver;

/**
 * Class InMemoryEventSourcedRepository
 * @package Ecotone\Modelling
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
#[Repository]
class InMemoryEventSourcedRepository implements EventSourcedRepository
{
    /**
     * @var array<string, array<string, Event[]>>
     */
    private array $eventsPerAggregate;
    private ?array $aggregateTypes;

    public function __construct(
        array $eventsPerAggregate = [],
        ?array $aggregateTypes = [],
        private readonly ?EventStore $eventStore = null,
    ) {
        $this->eventsPerAggregate = $eventsPerAggregate;
        $this->aggregateTypes = $aggregateTypes;
    }

    public static function createEmpty(): self
    {
        /** @phpstan-ignore-next-line */
        return new static([], []);
    }

    public static function createWithExistingAggregate(array $identifiers, string $aggregateClassName, array $events): self
    {
        $self = static::createEmpty();

        $events = array_map(static fn ($event) => Event::create($event), $events);

        $self->save($identifiers, $aggregateClassName, $events, [], count($events));

        return $self;
    }

    /**
     * @inheritDoc
     */
    public function canHandle(string $aggregateClassName): bool
    {
        if ($this->aggregateTypes === null) {
            return false;
        }

        if (in_array($aggregateClassName, $this->aggregateTypes)) {
            return true;
        }

        $classDefinition = ClassDefinition::createFor(Type::object($aggregateClassName));
        return $classDefinition->hasClassAnnotationOfPreciseType(Type::attribute(EventSourcingAggregate::class)) || $classDefinition->hasClassAnnotationOfPreciseType(Type::attribute(EventSourcingSaga::class));
    }

    /**
     * @inheritDoc
     */
    public function findBy(string $aggregateClassName, array $identifiers, int $fromVersion = 1): EventStream
    {
        if ($this->eventStore !== null) {
            return $this->findByViaEventStore($aggregateClassName, $identifiers, $fromVersion);
        }

        $key = $this->getKey($identifiers);

        if (isset($this->eventsPerAggregate[$aggregateClassName][$key])) {
            $events = $this->eventsPerAggregate[$aggregateClassName][$key];

            if ($fromVersion > 1) {
                $events = array_slice($events, $fromVersion - 1);
            }

            return EventStream::createWith(count($events), $events);
        }

        return EventStream::createEmpty();
    }

    /**
     * @inheritDoc
     */
    public function save(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
    {
        if ($this->eventStore !== null) {
            $this->saveViaEventStore($identifiers, $aggregateClassName, $events, $metadata, $versionBeforeHandling);

            return;
        }

        $key = $this->getKey($identifiers);

        if (! isset($this->eventsPerAggregate[$aggregateClassName][$key])) {
            $this->eventsPerAggregate[$aggregateClassName][$key] = $events;

            return;
        }

        $this->eventsPerAggregate[$aggregateClassName][$key] = array_merge($this->eventsPerAggregate[$aggregateClassName][$key], $events);
    }

    private function findByViaEventStore(string $aggregateClassName, array $identifiers, int $fromVersion): EventStream
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

    private function saveViaEventStore(array $identifiers, string $aggregateClassName, array $events, array $metadata, int $versionBeforeHandling): void
    {
        $aggregateId = (string) reset($identifiers);
        $appendCondition = AppendCondition::forAggregateFromSaveMetadata($aggregateClassName, $aggregateId, $versionBeforeHandling, $metadata);

        $this->eventStore->appendTo(AggregateDefinitionResolver::DEFAULT_STREAM, $events, $appendCondition);
    }

    private function getKey(array $identifiers): string
    {
        $key = '';
        foreach ($identifiers as $identifier) {
            $key .= (string)$identifier;
        }

        return $key;
    }
}
