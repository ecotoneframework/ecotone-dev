<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;

final class AggregateTypeMapping implements CompilableBuilder
{
    private function __construct(private array $aggregateTypeMapping)
    {
    }

    public static function createEmpty(): static
    {
        return new self([]);
    }

    public static function createWith(array $aggregateTypeMapping): static
    {
        return new self($aggregateTypeMapping);
    }

    public function getAggregateTypeMapping(): array
    {
        return $this->aggregateTypeMapping;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(self::class, [
            $this->aggregateTypeMapping,
        ], 'createWith');
    }
}
