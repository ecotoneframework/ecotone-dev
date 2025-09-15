<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\PartitionState;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\ProjectionStateStorage;
use Enqueue\Dbal\DbalConnectionFactory;

class DbalProjectionStateStorageBuilder implements ProjectionComponentBuilder
{
    public function __construct(private array $handledProjectionNames)
    {
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(
            DbalProjectionStateStorage::class,
            [
                new Reference(DbalConnectionFactory::class),
            ],
        );
    }

    public function canHandle(string $projectionName, string $component): bool
    {
        return $component === ProjectionStateStorage::class
            && in_array($projectionName, $this->handledProjectionNames, true);
    }
}
