<?php

namespace Test\Ecotone\Modelling\Fixture\EventSourcedSaga;

use Ecotone\Api\EventHandler;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\EventSourcingSaga;
use Ecotone\Api\Identifier;
use Ecotone\Api\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingSaga]
/**
 * licence Apache-2.0
 */
class OrderDispatch
{
    use WithAggregateVersioning;

    #[Identifier]
    private $orderId;
    /**
     * @var string
     */
    private $status;

    #[EventHandler]
    public static function createWith(OrderWasCreated $event): array
    {
        return [new OrderDispatchWasBegun($event->getOrderId())];
    }

    #[EventHandler]
    public function finishOrder(PaymentWasDoneEvent $event): array
    {
        return [new OrderDispatchWasFinished($event->getOrderId())];
    }

    public function getId(): string
    {
        return $this->orderId;
    }

    #[QueryHandler('order_dispatch.getStatus')]
    public function getStatus(): string
    {
        return $this->status;
    }

    #[EventSourcingHandler]
    public function whenOrderStarted(OrderDispatchWasBegun $event): void
    {
        $this->orderId = $event->getOrderId();
        $this->status = 'new';
    }

    #[EventSourcingHandler]
    public function whenPaymentDone(OrderDispatchWasFinished $event): void
    {
        $this->status = 'closed';
    }
}
