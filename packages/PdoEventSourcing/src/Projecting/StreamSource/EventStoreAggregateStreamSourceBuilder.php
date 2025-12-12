<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\StreamSource;

class EventStoreAggregateStreamSourceBuilder implements ProjectionComponentBuilder
{
    public function __construct(public readonly string $handledProjectionName, public ?string $aggregateType, private string $streamName)
    {
    }

    public function canHandle(string $projectionName, string $component): bool
    {
        return $component === StreamSource::class && $this->handledProjectionName === $projectionName;
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            EventStoreAggregateStreamSource::class,
            [
                new Reference(EventStore::class),
                $this->streamName,
                $this->aggregateType,
            ],
        );
    }
}
