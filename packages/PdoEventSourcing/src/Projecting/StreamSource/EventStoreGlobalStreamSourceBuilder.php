<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\EventSourcing\PdoStreamTableNameProvider;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\StreamFilter;
use Ecotone\Projecting\StreamSource;
use Enqueue\Dbal\DbalConnectionFactory;

class EventStoreGlobalStreamSourceBuilder implements ProjectionComponentBuilder
{
    public function __construct(
        private StreamFilter $streamFilter,
        private array $handledProjectionNames,
    ) {
    }

    public function canHandle(string $projectionName, string $component): bool
    {
        return $component === StreamSource::class && in_array($projectionName, $this->handledProjectionNames, true);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            EventStoreGlobalStreamSource::class,
            [
                Reference::to(DbalConnectionFactory::class),
                Reference::to(EcotoneClockInterface::class),
                new Definition(StreamFilter::class, [
                    $this->streamFilter->streamName,
                    $this->streamFilter->aggregateType,
                    $this->streamFilter->eventStoreReferenceName,
                    $this->streamFilter->eventNames,
                ]),
                Reference::to(PdoStreamTableNameProvider::class),
                5_000,
                new Definition(Duration::class, [60], 'seconds'),
            ],
        );
    }
}
