<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Config\StreamSourceBuilder;

class EventStoreAggregateStreamSourceBuilder implements StreamSourceBuilder
{
    public function __construct(public readonly string $streamSourceName, public string $aggregateType, private ?string $streamName = null) {
    }

    public function canHandle(string $projectionName): bool
    {
        return true;
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            EventStoreAggregateStreamSource::class,
            [
                new Reference(EventStore::class),
                $this->streamName ?? $this->streamSourceName,
                $this->aggregateType,
            ],
        );
    }
}