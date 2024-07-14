<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[AggregateType(self::AGGREGATE_TYPE)]
#[Stream(self::STREAM)]
/**
 * licence Apache-2.0
 */
final class Order
{
    use WithAggregateVersioning;

    public const AGGREGATE_TYPE = 'order';
    public const STREAM = 'order';

    #[Identifier]
    public string $orderId;

    public function __construct()
    {
    }

    #[EventSourcingHandler]
    public function applyOrderCreated(OrderCreated $event): void
    {
        $this->orderId = $event->orderId;
    }
}
