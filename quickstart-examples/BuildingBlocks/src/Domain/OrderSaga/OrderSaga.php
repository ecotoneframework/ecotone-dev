<?php

declare(strict_types=1);

namespace App\Domain\OrderSaga;

use App\Domain\Order\Event\OrderWasPlaced;
use App\Domain\OrderSaga\Event\OrderSagaStarted;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Endpoint\Delayed;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Saga;
use Ecotone\Modelling\Attribute\SagaIdentifier;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\WithEvents;
use Ramsey\Uuid\UuidInterface;

/**
 * Used for handling business process
 *
 * @link https://docs.ecotone.tech/modelling/saga
 */
#[Saga]
final class OrderSaga
{
    use WithEvents;

    /**
     * @param UuidInterface[] $productIds
     */
    public function __construct(
        #[SagaIdentifier] private UuidInterface $orderId,
        private array $productIds,
        private bool $isSuccessful = false
    ) {
        $this->recordThat(new OrderSagaStarted($this->orderId));
    }

    #[EventHandler]
    public static function place(OrderWasPlaced $event): self
    {
        return new self(
            $event->orderId,
            $event->productIds
        );
    }

    // first attempt to reserve products will happen asynchronous right away after Saga was started
    #[Asynchronous("orders")]
    #[EventHandler(endpointId: 'order.saga.whenFirstAttempt')]
    public function whenFirstAttempt(OrderSagaStarted $event, ProductReservationService $productReservationService, CommandBus $commandBus): void
    {
        if ($productReservationService->reserveProducts($this->orderId, $this->productIds)) {
            $this->isSuccessful = true;
            $commandBus->sendWithRouting("order.complete", metadata: ["aggregate.id" => $this->orderId->toString()]);
        }
    }

    // second attempt to reserve products will happen asynchronous right away after Saga was started
    #[Delayed(1000 * 60)] // 1 seconds * 60 minutes = 1 hour
    #[Asynchronous("orders")]
    #[EventHandler(endpointId: "order.saga.whenSecondAttempt")]
    public function whenSecondAttempt(OrderSagaStarted $event, ProductReservationService $productReservationService, CommandBus $commandBus): void
    {
        if ($this->isSuccessful) {
            return;
        }

        if ($productReservationService->reserveProducts($this->orderId, $this->productIds)) {
            $this->isSuccessful = true;
            $commandBus->sendWithRouting('order.complete', metadata: ['aggregate.id' => $this->orderId->toString()]);

            return;
        }

        $commandBus->sendWithRouting("order.cancel", metadata: ["aggregate.id" => $this->orderId->toString()]);
    }
}