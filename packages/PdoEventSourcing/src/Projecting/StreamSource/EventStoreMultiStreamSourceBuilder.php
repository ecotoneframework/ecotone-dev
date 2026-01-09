<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\StreamSource;

class EventStoreMultiStreamSourceBuilder implements ProjectionComponentBuilder
{
    /**
     * @param array<string,EventStoreMultiStreamSourceBuilder> $streamToSourceBuilder
     * @param string[] $handledProjectionNames
     */
    public function __construct(
        private array $streamToSourceBuilder,
        private array $handledProjectionNames,
    ) {
    }

    public function canHandle(string $projectionName, string $component): bool
    {
        return $component === StreamSource::class && in_array($projectionName, $this->handledProjectionNames, true);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        $sourcesDefinitions = array_map(function ($sourceBuilder) use ($builder) {
            return $sourceBuilder->compile($builder);
        }, $this->streamToSourceBuilder);

        return new Definition(
            EventStoreMultiStreamSource::class,
            [
                $sourcesDefinitions,
            ],
        );
    }
}
