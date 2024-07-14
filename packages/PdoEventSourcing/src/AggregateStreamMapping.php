<?php

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;

/**
 * licence Apache-2.0
 */
class AggregateStreamMapping implements CompilableBuilder
{
    private array $aggregateToStreamMapping = [];

    private function __construct(array $aggregateToStreamMapping)
    {
        $this->aggregateToStreamMapping = $aggregateToStreamMapping;
    }

    public static function createEmpty(): static
    {
        return new self([]);
    }

    public static function createWith(array $aggregateToStreamMapping): static
    {
        return new self($aggregateToStreamMapping);
    }

    public function getAggregateToStreamMapping(): array
    {
        return $this->aggregateToStreamMapping;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(self::class, [
            $this->aggregateToStreamMapping,
        ], 'createWith');
    }
}
