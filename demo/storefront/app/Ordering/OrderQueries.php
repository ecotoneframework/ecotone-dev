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
            ->map(fn (Order $order) => ['orderId' => $order->id, 'concertId' => $order->concert_id, 'status' => $order->status->value, 'seatIds' => $order->heldSeatIds()])
            ->all();
    }

    #[QueryHandler('customer.open_order')]
    public function openOrderOf(array $query): ?string
    {
        return Order::query()
            ->where('customer_id', $query['customerId'])
            ->where('concert_id', $query['concertId'])
            ->whereIn('status', Order::OPEN_STATUSES)
            ->value('id');
    }

    #[QueryHandler('concert.taken_seats')]
    public function seatsTakenByOtherOrders(array $query): array
    {
        return Order::query()
            ->where('concert_id', $query['concertId'])
            ->where('status', '<>', OrderStatus::FAILED)
            ->when($query['excludingOrderId'] !== null, fn ($orders) => $orders->where('id', '<>', $query['excludingOrderId']))
            ->get()
            ->flatMap(fn (Order $order) => $order->claimedSeatIds())
            ->unique()
            ->values()
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
            'heldSeats' => array_map(fn (array $heldSeat) => ['seatId' => $heldSeat['seatId'], 'expiresAt' => $heldSeat['expiresAt']], $order->held_seats),
            'lastRefusal' => $order->last_refusal,
            'paymentReference' => $order->payment_reference,
            'failureReason' => $order->failure_reason,
            'tickets' => $order->tickets,
        ];
    }
}
