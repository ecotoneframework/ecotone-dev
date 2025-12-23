<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Scheduling\Duration;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\StreamSource;
use Enqueue\Dbal\DbalConnectionFactory;

class EventStoreMultiStreamSourceBuilder implements ProjectionComponentBuilder
{
    /**
     * @param array<string,string> $streamToTable
     * @param string[] $handledProjectionNames
     */
    public function __construct(
        private array $streamToTable,
        private array $handledProjectionNames,
        private int $maxGapOffset = 5_000,
        private ?int $gapTimeoutSeconds = 60,
    ) {
    }

    public function canHandle(string $projectionName, string $component): bool
    {
        return $component === StreamSource::class && in_array($projectionName, $this->handledProjectionNames, true);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            EventStoreMultiStreamSource::class,
            [
                Reference::to(DbalConnectionFactory::class),
                Reference::to(EcotoneClockInterface::class),
                $this->streamToTable,
                $this->maxGapOffset,
                $this->gapTimeoutSeconds !== null ? new Definition(Duration::class, [$this->gapTimeoutSeconds], 'seconds') : null,
            ],
        );
    }
}
