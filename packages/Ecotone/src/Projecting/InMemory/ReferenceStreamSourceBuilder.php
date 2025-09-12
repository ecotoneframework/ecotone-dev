<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\InMemory;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Config\ProjectionComponentBuilder;
use Ecotone\Projecting\StreamSource;

class ReferenceStreamSourceBuilder implements ProjectionComponentBuilder
{
    /**
     * @param array<string> $streams projection names
     */
    public function __construct(private array $projectionNames, private string $referenceName)
    {
    }

    public function canHandle(string $projectionName, string $component): bool
    {
        return $component === StreamSource::class && \in_array($projectionName, $this->projectionNames, true);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Reference($this->referenceName);
    }
}