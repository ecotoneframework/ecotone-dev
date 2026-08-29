<?php

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\Config\EventStoreReference;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
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
            new Reference(EventStoreReference::EVENT_STORE_INSTANCE),
            $this->handledAggregateClassNames,
            new Reference(AggregateStreamMapping::class),
            new Reference(AggregateTypeMapping::class),
        ]);
    }
}
