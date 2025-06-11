<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\InMemory;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Projecting\Config\StreamSourceBuilder;

class ReferenceStreamSourceBuilder implements StreamSourceBuilder
{
    /**
     * @param array<string> $streams projection names
     */
    public function __construct(private array $projectionNames, private string $referenceName)
    {
    }

    public function canHandle(string $projectionName): bool
    {
        return \in_array($projectionName, $this->projectionNames, true);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Reference($this->referenceName);
    }
}