<?php

declare(strict_types=1);

namespace App\Payment;

use App\Ordering\BeginPayment;
use App\Ordering\FailOrder;
use App\Ordering\MarkPaid;
use App\Ordering\OrderFailed;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\CommandBus;
use Symfony\Component\Uid\Uuid;

final class Checkout
{
    #[CommandHandler]
    public function pay(Pay $command, #[Reference] CommandBus $commandBus): void
    {
        $commandBus->send(new BeginPayment(orderId: $command->orderId, confirmationEmailBounces: $command->confirmationEmailBounces));
        $commandBus->send(new ChargeCard(
            orderId: $command->orderId,
            paymentReference: 'pay_' . Uuid::v7()->toRfc4122(),
            outcome: $command->outcome,
        ));
    }

    #[Asynchronous('storefront_async')]
    #[CommandHandler(endpointId: 'charge_card')]
    public function charge(ChargeCard $command, #[Reference] PaymentProvider $paymentProvider, #[Reference] CommandBus $commandBus): void
    {
        $outcome = $paymentProvider->charge(orderId: $command->orderId, paymentReference: $command->paymentReference, outcome: $command->outcome);

        $commandBus->send(match ($outcome) {
            ChargeOutcome::CHARGED => new MarkPaid(orderId: $command->orderId, paymentReference: $command->paymentReference),
            ChargeOutcome::DECLINED => new FailOrder(orderId: $command->orderId, reason: 'The card was declined, so the order is not paid and its seats are released. Pick the seats again and pay with another card.'),
        });
    }

    #[Asynchronous('storefront_async')]
    #[EventHandler(endpointId: 'refund_failed_order')]
    public function refundFailedOrder(OrderFailed $event, #[Reference] PaymentProvider $paymentProvider): void
    {
        if ($event->paymentReference !== null) {
            $paymentProvider->refund($event->paymentReference);
        }
    }
}
