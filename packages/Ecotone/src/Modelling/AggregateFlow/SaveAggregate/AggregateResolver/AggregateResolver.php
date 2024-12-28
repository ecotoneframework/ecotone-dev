<?php

declare(strict_types=1);

namespace Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver;

use Ecotone\Messaging\Handler\Enricher\PropertyEditorAccessor;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\SaveAggregateServiceTemplate;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\Event;
use Ecotone\Modelling\EventSourcingExecutor\EventSourcingHandlerExecutor;
use Ecotone\Modelling\EventSourcingExecutor\GroupedEventSourcingExecutor;

final class AggregateResolver
{
    /**
     * @param AggregateClassDefinition[] $aggregateDefinitions
     */
    public function __construct(
        private array $aggregateDefinitions,
        private GroupedEventSourcingExecutor $eventSourcingExecutor,
        private PropertyEditorAccessor $propertyEditorAccessor,
        private PropertyReaderAccessor $propertyReaderAccessor,
    )
    {

    }

    /**
     * @return ResolvedAggregate[]
     */
    public function resolve(Message $message, bool $throwOnUnresolvableIdentifiers): array
    {
        return $this->resolveInternally($message, $throwOnUnresolvableIdentifiers, !$message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_OBJECT));
    }

    /**
     * @param Event[] $events
     */
    public function resolveAggregateInstance(array $events, AggregateClassDefinition $aggregateDefinition, ?object $actualAggregate): object
    {
        if ($aggregateDefinition->isEventSourced()) {
            return $this->eventSourcingExecutor->fillFor($aggregateDefinition->getClassName(), $actualAggregate, $events);
        }

        return $actualAggregate;
    }

    /**
     * @return Event[]
     */
    private function resolveEvents(AggregateClassDefinition $aggregateDefinition, ?object $actualAggregate, Message $message): array
    {
        /** Pure Event Sourced Aggregates returns events directly, therefore it lands as message payload */
        if ($aggregateDefinition->isPureEventSourcedAggregate()) {
            $returnType = TypeDescriptor::createFromVariable($message->getPayload());
            if ($this->isNewAggregateInstanceReturned($returnType)) {
                return [];
            }

            Assert::isTrue($returnType->isIterable(), "Pure event sourced aggregate should return iterable of events, but got {$returnType->toString()}");
            return $message->getPayload();
        }

        /** In other scenario than pure event sourced aggregate, we have to deal with aggregate instance */
        Assert::notNull($actualAggregate, "Aggregate {$aggregateDefinition->getClassName()} was not found. Can't fetch events for it.");

        if ($aggregateDefinition->hasEventRecordingMethod()) {
            return call_user_func([$actualAggregate, $aggregateDefinition->getEventRecorderMethod()]);
        }

        return [];
    }

    public function resolveInternally(Message $message, bool $throwOnUnresolvableIdentifiers, bool $isNewInstance): array
    {
        $resolvedAggregates = [];
        /** This will be null for factory methods */
        $calledAggregateInstance = $message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_OBJECT) ? $message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_OBJECT) : null;

        Assert::isTrue($message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_CLASS), "No aggregate class name was found in headers");
        Assert::keyExists($this->aggregateDefinitions, $message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_CLASS), "No aggregate was registered for {$message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_CLASS)}. Is this class name correct, and have you marked this class with #[Aggregate] attribute?");
        $aggregateDefinition = $this->aggregateDefinitions[$message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_CLASS)];

        if ($calledAggregateInstance || (is_null($calledAggregateInstance) && $aggregateDefinition->isPureEventSourcedAggregate())) {
            $resolvedAggregate = $this->resolveSingleAggregateFromMessage($aggregateDefinition, $calledAggregateInstance, $message, $throwOnUnresolvableIdentifiers, $isNewInstance);

            if ($resolvedAggregate) {
                $resolvedAggregates[] = $resolvedAggregate;
            }
        }

        $returnType = TypeDescriptor::createFromVariable($message->getPayload());
        if ($this->isNewAggregateInstanceReturned($returnType)) {
            $returnedResolvedAggregates = $this->resolveInternally(
                MessageBuilder::fromMessage($message)
                    ->setPayload([])
                    ->setHeader(AggregateMessage::CALLED_AGGREGATE_OBJECT, $message->getPayload())
                    ->setHeader(AggregateMessage::CALLED_AGGREGATE_CLASS, $returnType->getTypeHint())
                    ->setHeader(AggregateMessage::TARGET_VERSION, 0)
                    ->removeHeaders([AggregateMessage::AGGREGATE_ID, AggregateMessage::NULL_EXECUTION_RESULT])
                    ->build(),
                true,
                true,
            );

            if (count($resolvedAggregates) === count($returnedResolvedAggregates)) {
                if ($resolvedAggregates[0]->getAggregateInstance() === $returnedResolvedAggregates[0]->getAggregateInstance()) {
                    return $resolvedAggregates;
                }
            }

            $resolvedAggregates = array_merge($resolvedAggregates, $returnedResolvedAggregates);
        }

        return $resolvedAggregates;
    }

    public function resolveSingleAggregateFromMessage(AggregateClassDefinition $aggregateDefinition, null|object $calledAggregateInstance, Message $message, bool $throwOnUnresolvableIdentifiers, bool $isNewInstance): ResolvedAggregate|null
    {
        $events = SaveAggregateServiceTemplate::buildEcotoneEvents(
            $this->resolveEvents($aggregateDefinition, $calledAggregateInstance, $message),
            $aggregateDefinition->getDefinition()->getClassName(),
            $message,
        );

        /** Nothing to save */
        if ($aggregateDefinition->isPureEventSourcedAggregate() && $events === []) {
            return null;
        }

        $instance = $this->resolveAggregateInstance($events, $aggregateDefinition, $calledAggregateInstance);
        SaveAggregateServiceTemplate::enrichVersionIfNeeded(
            $this->propertyEditorAccessor,
            SaveAggregateServiceTemplate::resolveVersionBeforeHandling($message),
            $instance,
            $message,
            $aggregateDefinition->getAggregateVersionProperty(),
            $aggregateDefinition->isAggregateVersionAutomaticallyIncreased(),
        );

        $identifiers = SaveAggregateServiceTemplate::getAggregateIds(
            $this->propertyReaderAccessor,
            $message->getHeaders()->headers(),
            $instance,
            $aggregateDefinition,
            $aggregateDefinition->isEventSourced(),
        );

        return new ResolvedAggregate(
            $aggregateDefinition,
            $isNewInstance,
            $instance,
            $message->getHeaders()->containsKey(AggregateMessage::TARGET_VERSION) ? $message->getHeaders()->get(AggregateMessage::TARGET_VERSION) : null,
            $identifiers,
            $events
        );
    }

    public function isNewAggregateInstanceReturned(TypeDescriptor|\Ecotone\Messaging\Handler\Type $returnType): bool
    {
        $isNewAggregateInstanceReturned = $returnType->isClassNotInterface() && isset($this->aggregateDefinitions[$returnType->getTypeHint()]);
        return $isNewAggregateInstanceReturned;
    }
}