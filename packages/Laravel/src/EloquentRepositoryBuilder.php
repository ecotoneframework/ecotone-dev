<?php

namespace Ecotone\Laravel;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\RepositoryBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * licence Apache-2.0
 */
final class EloquentRepositoryBuilder implements RepositoryBuilder
{
    public function canHandle(string $aggregateClassName): bool
    {
        return is_subclass_of($aggregateClassName, Model::class);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(EloquentRepository::class, [
            Reference::to(AggregateDefinitionRegistry::class),
            Reference::to(PropertyReaderAccessor::class),
        ]);
    }
}
