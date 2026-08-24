<?php

declare(strict_types=1);

namespace App\Domain\OrderSaga;

use App\Domain\Order\Event\OrderWasPlaced;
use App\Domain\OrderSaga\Event\OrderSagaStarted;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\Delayed;
use Ecotone\Api\EventHandler;
use Ecotone\Api\Identifier;
use Ecotone\Api\Saga;
use Ecotone\Api\CommandBus;
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
        #[Identifier] private UuidInterface $orderId,
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