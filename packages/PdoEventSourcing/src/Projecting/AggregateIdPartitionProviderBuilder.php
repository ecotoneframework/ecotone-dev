<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Ecotone\EventSourcing\PdoStreamTableNameProvider;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\PartitionProvider;
use Enqueue\Dbal\DbalConnectionFactory;

class AggregateIdPartitionProviderBuilder implements ProjectionComponentBuilder
{
    public function __construct(public readonly string $handledProjectionName)
    {
    }

    public function canHandle(string $projectionName, string $component): bool
    {
        return $component === PartitionProvider::class && $this->handledProjectionName === $projectionName;
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(AggregateIdPartitionProvider::class, [
            Reference::to(DbalConnectionFactory::class),
            Reference::to(PdoStreamTableNameProvider::class),
        ]);
    }
}
