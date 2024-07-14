<?php

namespace Test\Ecotone\EventSourcing\Fixture\BasketWithSynchronousEventDrivenSaga;

use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
class Saga
{
    use WithAggregateVersioning;
    #[Identifier]
    private string $id;

    #[EventHandler]
    public static function start(BasketWasCreated $event): array
    {
        return [new SagaStarted($event->getId())];
    }

    #[EventHandler]
    public function whenSagaStarted(SagaStarted $event, CommandBus $commandBus): array
    {
        if ($event->getId() === '1000') {
            $commandBus->send(new AddProduct($event->getId(), 'chocolate'));
        }

        return [];
    }

    #[EventSourcingHandler()]
    public function applySagaStarted(SagaStarted $event): void
    {
        $this->id = $event->getId();
    }

}
