<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting;

use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Tracking\SequenceAccessor\GlobalSequenceFactory;

class EventStoreStreamSourceBuilder
{
    public function __construct(public readonly string $streamSourceName, private ?string $streamName = null) {
    }

    public function compile(): Definition|Reference
    {
        return new Definition(
            EventStoreStreamSource::class,
            [
                new Reference(EventStore::class),
                $this->streamName ?? $this->streamSourceName,
                new Definition(GlobalSequenceFactory::class),
            ],
        );
    }
}