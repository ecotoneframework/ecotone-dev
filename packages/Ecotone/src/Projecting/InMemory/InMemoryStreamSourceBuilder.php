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

class InMemoryStreamSourceBuilder implements StreamSourceBuilder
{
    /**
     * @param array<string, InMemoryStreamSource> $streams
     */
    public function __construct(private array $streams)
    {
    }

    public function canHandle(Projection $projection): bool
    {
        return isset($this->streams[$projection->streamSourceReference]);
    }

    /**
     * @param non-empty-list<Projection> $projections
     */
    public function compile(MessagingContainerBuilder $builder, array $projections): Definition|Reference
    {
        return new Definition(InMemoryStreamSource::class);
    }
}