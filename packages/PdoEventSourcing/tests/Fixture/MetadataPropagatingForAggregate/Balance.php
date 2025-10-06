<?php

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Ramsey\Uuid\Rfc4122\UuidV4;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
class Balance
{
    use WithAggregateVersioning;

    #[Identifier]
    private UuidV4 $balanceId;

    #[CommandHandler('createBalance')]
    public static function create(UuidV4 $balanceId): array
    {
        return [new BalanceCreated($balanceId)];
    }

    #[EventSourcingHandler]
    public function whenOrderWasPlaced(BalanceCreated $event): void
    {
        $this->balanceId = $event->balanceId;
    }
}
