<?php

declare(strict_types=1);

namespace Ecotone\Modelling\AggregateFlow\SaveAggregate;

use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\ResolvedAggregate;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\Repository\AggregateRepository;

/**
 * licence Apache-2.0
 */
final class SaveAggregateTestSetupService implements MessageProcessor
{
    public function __construct(
        private AggregateDefinitionRegistry $aggregateDefinitionRegistry,
        private PropertyReaderAccessor $propertyReaderAccessor,
        private ConversionService $conversionService,
        private HeaderMapper $headerMapper,
        private EventMapper $eventMapper,
        private AggregateRepository $aggregateRepository,
    ) { }

    public function process(Message $message): Message|null
    {
        $resolvedAggregate = $this->resolveAggregate($message);
        $metadata = MessageHeaders::unsetNonUserKeys($message->getHeaders()->headers());

        if (! $resolvedAggregate) {
            return MessageBuilder::fromMessage($message)->build();
        }

        $version = $resolvedAggregate->getVersionBeforeHandling();

        $this->aggregateRepository->save(
            $resolvedAggregate,
            $metadata,
            $version
        );

        return MessageBuilder::fromMessage($message)->build();
    }

    private function resolveAggregate(Message $message): ResolvedAggregate
    {
        $aggregateDefinition = $this->aggregateDefinitionRegistry->getFor(TypeDescriptor::create($message->getHeaders()->get(AggregateMessage::TEST_SETUP_AGGREGATE_CLASS)));
        $calledAggregateInstance = $message->getHeaders()->containsKey(AggregateMessage::TEST_SETUP_AGGREGATE_INSTANCE) ? $message->getHeaders()->get(AggregateMessage::TEST_SETUP_AGGREGATE_INSTANCE) : null;
        $versionBeforeHandling = $message->getHeaders()->containsKey(AggregateMessage::TEST_SETUP_AGGREGATE_VERSION) ? $message->getHeaders()->get(AggregateMessage::TEST_SETUP_AGGREGATE_VERSION) : 0;
        $identifiers = SaveAggregateServiceTemplate::getAggregateIds(
            $this->propertyReaderAccessor,
            $message->getHeaders()->headers(),
            $calledAggregateInstance,
            $aggregateDefinition,
            $aggregateDefinition->isEventSourced(),
        );

        $events = SaveAggregateServiceTemplate::buildEcotoneEvents(
            $this->resolveEvents($message),
            $aggregateDefinition->getDefinition()->getClassName(),
            $message,
            $this->headerMapper,
            $this->conversionService,
            $this->eventMapper,
        );

        $enrichedEvents = [];
        $incrementedVersion = $versionBeforeHandling;
        foreach ($events as $event) {
            $incrementedVersion += 1;

            $enrichedEvents[] = $event->withAddedMetadata([
                MessageHeaders::EVENT_AGGREGATE_ID => count($identifiers) == 1 ? $identifiers[array_key_first($identifiers)] : $identifiers,
                MessageHeaders::EVENT_AGGREGATE_TYPE => $aggregateDefinition->getAggregateClassType(),
                MessageHeaders::EVENT_AGGREGATE_VERSION => $incrementedVersion,
            ]);
        }

        return new ResolvedAggregate(
            aggregateClassDefinition: $aggregateDefinition,
            isNewInstance: true,
            aggregateInstance: $calledAggregateInstance,
            versionBeforeHandling: $versionBeforeHandling,
            identifiers: $identifiers,
            events: $enrichedEvents,
        );
    }

    private function resolveEvents(Message $message): array
    {
        if ($message->getHeaders()->containsKey(AggregateMessage::TEST_SETUP_AGGREGATE_EVENTS)) {
            return $message->getHeaders()->get(AggregateMessage::TEST_SETUP_AGGREGATE_EVENTS);
        }

        return [];
    }
}
