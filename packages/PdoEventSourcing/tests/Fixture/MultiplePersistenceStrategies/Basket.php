<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

use Ecotone\Api\AggregateType;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[AggregateType(self::AGGREGATE_TYPE)]
#[Stream(self::STREAM)]
/**
 * licence Apache-2.0
 */
final class Basket
{
    use WithAggregateVersioning;

    public const AGGREGATE_TYPE = 'basket';
    public const STREAM = 'basket';

    #[Identifier]
    public string $basketId;

    public function __construct()
    {
    }

    #[EventSourcingHandler]
    public function applyBasketCreated(BasketCreated $event): void
    {
        $this->basketId = $event->basketId;
    }
}
