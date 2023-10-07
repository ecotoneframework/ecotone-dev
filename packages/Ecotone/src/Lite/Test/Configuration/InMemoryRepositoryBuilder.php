<?php

declare(strict_types=1);

namespace Ecotone\Lite\Test\Configuration;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Modelling\EventSourcedRepository;
use Ecotone\Modelling\InMemoryEventSourcedRepository;
use Ecotone\Modelling\InMemoryStandardRepository;
use Ecotone\Modelling\RepositoryBuilder;
use Ecotone\Modelling\StandardRepository;

final class InMemoryRepositoryBuilder implements RepositoryBuilder, CompilableBuilder
{
    public function __construct(private array $aggregateClassNames, private bool $isEventSourced)
    {
    }

    public static function createForAllStateStoredAggregates(): self
    {
        return new self([], false);
    }

    public static function createForSetOfStateStoredAggregates(array $aggregateClassNames)
    {
        return new self($aggregateClassNames, false);
    }

    public static function createForAllEventSourcedAggregates(): self
    {
        return new self([], true);
    }

    public static function createForSetOfEventSourcedAggregates(array $aggregateClassNames)
    {
        return new self($aggregateClassNames, true);
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return isset($this->aggregateClassNames[$aggregateClassName]);
    }

    public function isEventSourced(): bool
    {
        return $this->isEventSourced;
    }

    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): EventSourcedRepository|StandardRepository
    {
        if ($this->isEventSourced) {
            return new InMemoryEventSourcedRepository([], $this->aggregateClassNames);
        } else {
            return new InMemoryStandardRepository([], $this->aggregateClassNames);
        }
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        return match ($this->isEventSourced) {
            true => new Definition(
                InMemoryEventSourcedRepository::class,
                [
                    [],
                    $this->aggregateClassNames
                ]
            ),
            false => new Definition(
                InMemoryStandardRepository::class,
                [
                    [],
                    $this->aggregateClassNames
                ]
            )
        };
    }
}
