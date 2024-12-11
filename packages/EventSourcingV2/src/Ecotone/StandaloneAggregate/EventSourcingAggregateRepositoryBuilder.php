<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone\StandaloneAggregate;

use Ecotone\EventSourcingV2\EventStore\EventStore;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Modelling\RepositoryBuilder;

class EventSourcingAggregateRepositoryBuilder implements RepositoryBuilder
{
    public function __construct(private array $eventSourcedAggregatesClasses)
    {
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            \Ecotone\EventSourcingV2\Ecotone\StandaloneAggregate\EventSourcedAggregateRepository::class,
            [
                new Reference(EventStore::class),
            ]
        );
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return in_array($aggregateClassName, $this->eventSourcedAggregatesClasses, true);
    }

    public function isEventSourced(): bool
    {
        return false;
    }
}