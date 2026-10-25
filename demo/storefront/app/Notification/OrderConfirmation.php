<?php

declare(strict_types=1);

namespace App\Notification;

use App\Concert\SeatCode;
use App\Ordering\OrderTicketsIssued;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\QueryBus;

final class OrderConfirmation
{
    #[Asynchronous('storefront_async')]
    #[EventHandler(endpointId: 'order_confirmation_email')]
    public function emailTickets(OrderTicketsIssued $event, #[Reference] QueryBus $queryBus, #[Reference] EmailSender $emailSender): void
    {
        $customer = $queryBus->sendWithRouting('customer.detail', $event->customerId);
        $order = $queryBus->sendWithRouting('order.detail', $event->orderId);

        $emailSender->sendOrderConfirmation(
            orderId: $event->orderId,
            recipient: $customer['email'],
            subject: 'Your tickets for order ' . $event->orderId,
            body: sprintf(
                "Hello %s,\n\nyour tickets are ready:\n\n%s\n\nShow the entry code at the door.",
                $customer['name'],
                implode("\n", array_map(
                    fn (array $ticket) => sprintf('%s  seat %s (%s)  entry code %s', $ticket['ticketNumber'], SeatCode::ofSeatId($ticket['seatId']), $ticket['tier'], $ticket['entryCode']),
                    $order['tickets'],
                )),
            ),
        );
    }
}
