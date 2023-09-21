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
