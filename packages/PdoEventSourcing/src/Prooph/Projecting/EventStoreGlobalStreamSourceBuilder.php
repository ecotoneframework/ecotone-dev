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

class EventStoreGlobalStreamSourceBuilder implements StreamSourceBuilder
{
    public function __construct(
        private string $streamName,
        private array $handledProjectionNames,
    ) {
    }

    public function canHandle(string $projectionName): bool
    {
        return in_array($projectionName, $this->handledProjectionNames, true);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            EventStoreGlobalStreamSource::class,
            [
                Reference::to(EventStore::class),
                $this->streamName,
            ],
        );
    }
}