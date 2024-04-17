<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Payment;

use App\Workflow\Saga\Application\Payment\Command\TakePayment;
use App\Workflow\Saga\Application\Payment\Event\PaymentFailed;
use App\Workflow\Saga\Application\Payment\Event\PaymentWasSuccessful;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\EventBus;

final readonly class PaymentService
{
    public function __construct(
        private PaymentProcessor $paymentProcessor
    ) {
    }

    #[CommandHandler('takePayment')]
    public function takePayment(TakePayment $takePayment, EventBus $eventBus): void
    {
        if ($this->paymentProcessor->takePayment($takePayment->orderId, $takePayment->amount)) {
            $eventBus->publish(new PaymentWasSuccessful($takePayment->orderId));
        } else {
            $eventBus->publish(new PaymentFailed($takePayment->orderId));
        }
    }
}