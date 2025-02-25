<?php

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\Prooph\EcotoneEventStoreProophWrapper;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Modelling\RepositoryBuilder;

/**
 * licence Apache-2.0
 */
final class EventSourcingRepositoryBuilder implements RepositoryBuilder
{
    private array $handledAggregateClassNames = [];

    private function __construct()
    {

    }

    public static function create(): static
    {
        return new static();
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

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(EventSourcingRepository::class, [
            new Definition(EcotoneEventStoreProophWrapper::class, [
                new Reference(LazyProophEventStore::class),
                new Reference(ConversionService::REFERENCE_NAME),
                new Reference(ProophEventMapper::class),
            ], 'prepare'),
            $this->handledAggregateClassNames,
            new Reference(EventSourcingConfiguration::class),
            new Reference(AggregateStreamMapping::class),
            new Reference(AggregateTypeMapping::class),
        ]);
    }
}
