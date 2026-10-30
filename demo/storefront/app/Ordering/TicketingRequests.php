<?php

declare(strict_types=1);

namespace App\Ordering;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;

final class TicketingRequests
{
    #[Asynchronous('storefront_async')]
    #[EventHandler(endpointId: 'hold_requested_seat')]
    public function holdRequestedSeat(SeatRequested $event, #[Reference] DistributedBus $distributedBus): void
    {
        $this->send(distributedBus: $distributedBus, routingKey: 'tickets.hold', orderId: $event->orderId, concertId: $event->concertId, payload: [
            'orderId' => $event->orderId,
            'concertId' => $event->concertId,
            'seatId' => $event->seatId,
            'customerId' => $event->customerId,
            'holdId' => $event->holdId,
        ], replyRoutingKey: 'tickets.hold.result');
    }

    #[Asynchronous('storefront_async')]
    #[EventHandler(endpointId: 'release_seat')]
    public function releaseSeat(SeatReleased $event, #[Reference] DistributedBus $distributedBus): void
    {
        $this->sendRelease(distributedBus: $distributedBus, orderId: $event->orderId, concertId: $event->concertId, customerId: $event->customerId, seatId: $event->seatId, holdId: $event->holdId);
    }

    #[Asynchronous('storefront_async')]
    #[EventHandler(endpointId: 'release_seats_of_failed_order')]
    public function releaseSeatsOfFailedOrder(OrderFailed $event, #[Reference] DistributedBus $distributedBus): void
    {
        foreach ($event->holdIdsOfHeldSeats as $seatId => $holdId) {
            $this->sendRelease(distributedBus: $distributedBus, orderId: $event->orderId, concertId: $event->concertId, customerId: $event->customerId, seatId: $seatId, holdId: $holdId);
        }
    }

    #[Asynchronous('storefront_async')]
    #[EventHandler(endpointId: 'confirm_paid_seats')]
    public function confirmPaidSeats(OrderPaid $event, #[Reference] DistributedBus $distributedBus): void
    {
        $this->send(distributedBus: $distributedBus, routingKey: 'tickets.confirm', orderId: $event->orderId, concertId: $event->concertId, payload: [
            'orderId' => $event->orderId,
            'concertId' => $event->concertId,
            'customerId' => $event->customerId,
            'seatIds' => $event->seatIds,
            'paymentReference' => $event->paymentReference,
        ], replyRoutingKey: 'tickets.confirm.result');
    }

    private function sendRelease(DistributedBus $distributedBus, string $orderId, string $concertId, string $customerId, string $seatId, string $holdId): void
    {
        $this->send(distributedBus: $distributedBus, routingKey: 'tickets.release', orderId: $orderId, concertId: $concertId, payload: [
            'orderId' => $orderId,
            'concertId' => $concertId,
            'seatId' => $seatId,
            'customerId' => $customerId,
            'holdId' => $holdId,
        ], replyRoutingKey: null);
    }

    private function send(DistributedBus $distributedBus, string $routingKey, string $orderId, string $concertId, array $payload, ?string $replyRoutingKey): void
    {
        $distributedBus->sendMessage(
            targetServiceName: 'ticketing',
            routingKey: $routingKey,
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
            sourceMediaType: MediaType::APPLICATION_JSON,
            metadata: ['concertId' => $concertId, 'orderId' => $orderId],
            replyRoutingKey: $replyRoutingKey,
        );
    }
}
