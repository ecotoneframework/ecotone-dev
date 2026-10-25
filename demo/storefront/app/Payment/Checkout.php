<?php

declare(strict_types=1);

namespace App\Payment;

use App\Ordering\BeginPayment;
use App\Ordering\FailOrder;
use App\Ordering\MarkPaid;
use App\Ordering\OrderFailed;
use DomainException;
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
        if (preg_match('/^[0-9]{12,19}\z/', $command->cardNumber) !== 1) {
            throw new DomainException(sprintf('The card number for order %s is not 12 to 19 digits, so the card cannot be charged. Enter the number without spaces, like 4242424242424242.', $command->orderId));
        }
        $commandBus->send(new BeginPayment(orderId: $command->orderId));
        $commandBus->send(new ChargeCard(
            orderId: $command->orderId,
            paymentReference: 'pay_' . Uuid::v7()->toRfc4122(),
            cardEnding: substr($command->cardNumber, -4),
        ));
    }

    #[Asynchronous('storefront_async')]
    #[CommandHandler(endpointId: 'charge_card')]
    public function charge(ChargeCard $command, #[Reference] PaymentProvider $paymentProvider, #[Reference] CommandBus $commandBus): void
    {
        $outcome = $paymentProvider->charge(orderId: $command->orderId, paymentReference: $command->paymentReference, cardEnding: $command->cardEnding);

        $commandBus->send(match ($outcome) {
            ChargeOutcome::CHARGED => new MarkPaid(orderId: $command->orderId, paymentReference: $command->paymentReference),
            ChargeOutcome::DECLINED => new FailOrder(orderId: $command->orderId, reason: sprintf('The card ending %s was declined, so the order is not paid and its seats are released. Pick the seats again and pay with another card.', $command->cardEnding)),
        });
    }

    #[EventHandler]
    public function refundFailedOrder(OrderFailed $event, #[Reference] PaymentProvider $paymentProvider): void
    {
        if ($event->paymentReference !== null) {
            $paymentProvider->refund($event->paymentReference);
        }
    }
}
