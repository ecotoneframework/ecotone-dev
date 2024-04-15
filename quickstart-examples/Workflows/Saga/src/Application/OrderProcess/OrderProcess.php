<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\OrderProcess;

use App\Workflow\Saga\Application\Order\Event\OrderWasPlaced;
use App\Workflow\Saga\Application\Order\OrderService;
use App\Workflow\Saga\Application\OrderProcess\Event\OrderProcessWasStarted;
use App\Workflow\Saga\Application\Payment\Command\TakePayment;
use App\Workflow\Saga\Application\Payment\Event\PaymentFailed;
use App\Workflow\Saga\Application\Payment\Event\PaymentWasSuccessful;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Endpoint\Delayed;
use Ecotone\Messaging\Scheduling\TimeSpan;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\Attribute\Saga;
use Ecotone\Modelling\WithEvents;

#[Saga]
final class OrderProcess
{
    use WithEvents;

    const MAXIMUM_PAYMENT_ATTEMPTS = 2;

    private function __construct(
        #[Identifier] private string $orderId,
        private OrderProcessStatus   $orderStatus,
        private int $paymentAttempt = 1,
    ) {
        $this->recordThat(new OrderProcessWasStarted($this->orderId));
    }

    #[EventHandler]
    public static function startWhen(OrderWasPlaced $event): self
    {
        return new self($event->orderId, OrderProcessStatus::PLACED);
    }

    #[Asynchronous('async_saga')]
    #[EventHandler(endpointId: "takePaymentEndpoint", outputChannelName: "takePayment")]
    public function whenOrderProcessStarted(OrderProcessWasStarted $event, OrderService $orderService): TakePayment
    {
        return new TakePayment(
            $this->orderId,
            $orderService->getTotalPriceFor($this->orderId)
        );
    }

    #[EventHandler]
    public function whenPaymentWasSuccessful(PaymentWasSuccessful $event): void
    {
        $this->orderStatus = OrderProcessStatus::READY_TO_BE_SHIPPED;
    }

    #[Delayed(new TimeSpan(hours: 1))]
    #[Asynchronous('async_saga')]
    #[EventHandler(endpointId: "whenPaymentFailedEndpoint", outputChannelName: "takePayment")]
    public function whenPaymentFailed(PaymentFailed $event, OrderService $orderService): ?TakePayment
    {
        if ($this->paymentAttempt >= self::MAXIMUM_PAYMENT_ATTEMPTS) {
            return null;
        }

        $this->orderStatus = OrderProcessStatus::PAYMENT_FAILED;
        $this->paymentAttempt++;

        return new TakePayment(
            $this->orderId,
            $orderService->getTotalPriceFor($this->orderId)
        );
    }

    #[EventHandler]
    public function whenSecondPaymentFailedCancelOrder(PaymentFailed $event): void
    {
        if ($this->paymentAttempt >= self::MAXIMUM_PAYMENT_ATTEMPTS) {
            $this->orderStatus = OrderProcessStatus::CANCELLED;
        }
    }

    #[QueryHandler("orderProcess.getStatus")]
    public function getStatus(): OrderProcessStatus
    {
        return $this->orderStatus;
    }
}