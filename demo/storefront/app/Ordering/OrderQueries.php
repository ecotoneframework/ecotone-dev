<?php

declare(strict_types=1);

namespace App\Ordering;

use DomainException;
use Ecotone\Api\Attribute\QueryHandler;

final class OrderQueries
{
    #[QueryHandler('customer.orders')]
    public function ordersOf(string $customerId): array
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order) => ['orderId' => $order->id, 'concertId' => $order->concert_id, 'status' => $order->status->value, 'seatIds' => $order->seat_ids])
            ->all();
    }

    #[QueryHandler('order.detail')]
    public function detail(string $orderId): array
    {
        $order = Order::query()->find($orderId) ?? throw new DomainException(sprintf('There is no order %s. Start one with StartOrder, or list the customer\'s orders with the customer.orders query to find its id.', $orderId));

        return [
            'orderId' => $order->id,
            'customerId' => $order->customer_id,
            'concertId' => $order->concert_id,
            'status' => $order->status->value,
            'seatIds' => $order->seat_ids,
            'paymentReference' => $order->payment_reference,
            'failureReason' => $order->failure_reason,
        ];
    }
}
