<?php

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\Prooph\EcotoneEventStoreProophWrapper;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Modelling\RepositoryBuilder;

final class EventSourcingRepositoryBuilder implements RepositoryBuilder
{
    private array $handledAggregateClassNames = [];
    private array $headerMapper = [];
    private EventSourcingConfiguration $eventSourcingConfiguration;

    private function __construct(EventSourcingConfiguration $eventSourcingConfiguration)
    {
        $this->eventSourcingConfiguration = $eventSourcingConfiguration;
    }

    public static function create(EventSourcingConfiguration $eventSourcingConfiguration): static
    {
        return new static($eventSourcingConfiguration);
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return in_array($aggregateClassName, $this->handledAggregateClassNames);
    }

    public function withAggregateClassesToHandle(array $aggregateClassesToHandle): self
    {
        $this->handledAggregateClassNames = $aggregateClassesToHandle;

        return $this;
    }

    public function withMetadataMapper(string $headerMapper): self
    {
        $this->headerMapper = explode(',', $headerMapper);

        return $this;
    }

    public function isEventSourced(): bool
    {
        return true;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $headerMapper = $this->headerMapper
            ? DefaultHeaderMapper::createWith($this->headerMapper, $this->headerMapper)
            : DefaultHeaderMapper::createAllHeadersMapping();

        $documentStoreReferences = [];
        foreach ($this->eventSourcingConfiguration->getSnapshotsConfig() as $aggregateClass => $config) {
            $documentStoreReferences[$aggregateClass] = new Reference($config['documentStore']);
        }

        return new Definition(EventSourcingRepository::class, [
            new Definition(EcotoneEventStoreProophWrapper::class, [
                new Reference(LazyProophEventStore::class),
                new Reference(ConversionService::REFERENCE_NAME),
                new Reference(EventMapper::class),
            ], 'prepare'),
            $this->handledAggregateClassNames,
            $headerMapper,
            new Reference(EventSourcingConfiguration::class),
            new Reference(AggregateStreamMapping::class),
            new Reference(AggregateTypeMapping::class),
            $documentStoreReferences,
            new Reference(ConversionService::REFERENCE_NAME),
        ]);
    }
}
