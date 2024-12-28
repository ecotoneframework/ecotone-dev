<?php

namespace Ecotone\Modelling\AggregateFlow\SaveAggregate;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\Enricher\PropertyEditorAccessor;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateClassDefinitionProvider;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateResolver;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\AggregateIdentifierMethod;
use Ecotone\Modelling\Attribute\AggregateVersion;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\BaseEventSourcingConfiguration;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\LazyEventSourcedRepository;
use Ecotone\Modelling\LazyStandardRepository;
use Ecotone\Modelling\NoCorrectIdentifierDefinedException;
use Psr\Container\ContainerInterface;

/**
 * Class AggregateCallingCommandHandlerBuilder
 * @package Ecotone\Modelling
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class SaveAggregateServiceBuilder implements CompilableBuilder
{
    /**
     * @var string[]
     */
    private array $aggregateRepositoryReferenceNames = [];

    private ?string $calledAggregateClassName = null;

    private function __construct(
        ClassDefinition $aggregateClassDefinition,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        private BaseEventSourcingConfiguration $eventSourcingConfiguration,
        private bool $publishEvents = true,
        private bool $passThroughResult = false,
    ) {
    }

    /**
     * @param string[] $aggregateClasses
     */
    public static function create(
        ClassDefinition $aggregateClassDefinition,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        BaseEventSourcingConfiguration $eventSourcingConfiguration,
    ): self {
        return new self($aggregateClassDefinition, $interfaceToCallRegistry, $eventSourcingConfiguration);
    }

    /**
     * @param string[] $aggregateRepositoryReferenceNames
     */
    public function withAggregateRepositoryFactories(array $aggregateRepositoryReferenceNames): self
    {
        $this->aggregateRepositoryReferenceNames = $aggregateRepositoryReferenceNames;

        return $this;
    }

    public function withPublishEvents(bool $publishEvents): self
    {
        $this->publishEvents = $publishEvents;

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $eventSourcedRepository = new Definition(LazyEventSourcedRepository::class, [
            array_map(static fn ($id) => new Reference($id), $this->aggregateRepositoryReferenceNames),
        ], 'create');

        $standardRepository = new Definition(LazyStandardRepository::class, [
            array_map(static fn ($id) => new Reference($id), $this->aggregateRepositoryReferenceNames),
        ], 'create');

        return new Definition(SaveAggregateService::class, [
            $eventSourcedRepository,
            $standardRepository,
            new Reference(AggregateResolver::class),
            $this->eventSourcingConfiguration,
            $this->publishEvents,
            Reference::to(EventBus::class),
            Reference::to(ContainerInterface::class)
        ]);
    }

    public function __toString()
    {
        return sprintf('Save Aggregate Processor - %s', $this->calledAggregateClassName);
    }
}
