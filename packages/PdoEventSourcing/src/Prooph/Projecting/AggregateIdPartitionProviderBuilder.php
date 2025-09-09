<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Config\PartitionProviderBuilder;
use Enqueue\Dbal\DbalConnectionFactory;

class AggregateIdPartitionProviderBuilder implements PartitionProviderBuilder
{
    public function __construct(public readonly string $handledProjectionName, public readonly ?string $aggregateType, private string $streamName)
    {
    }

    public function canHandle(string $projectionName): bool
    {
        return $this->handledProjectionName === $projectionName;
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(AggregateIdPartitionProvider::class, [
            Reference::to(DbalConnectionFactory::class),
            $this->aggregateType,
            $this->streamName
        ]);
    }
}