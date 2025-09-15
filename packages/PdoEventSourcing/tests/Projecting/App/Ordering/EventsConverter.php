<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering;

use Ecotone\Messaging\Attribute\Converter;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasCancelled;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasPlaced;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasReconfirmed;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasReturned;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasShipped;

class EventsConverter
{
    #[Converter]
    public function orderWasPlacedToArray(OrderWasPlaced $event): array
    {
        return [
            'orderId' => $event->orderId,
            'product' => $event->product,
            'quantity' => $event->quantity,
            'fail' => $event->fail,
        ];
    }

    #[Converter]
    public function arrayToOrderWasPlaced(array $data): OrderWasPlaced
    {
        return new OrderWasPlaced(
            $data['orderId'],
            $data['product'],
            (int) $data['quantity'],
            isset($data['fail']) ? (bool) $data['fail'] : false
        );
    }

    #[Converter]
    public function orderWasShippedToArray(OrderWasShipped $event): array
    {
        return [
            'orderId' => $event->orderId,
            'fail' => $event->fail,
        ];
    }

    #[Converter]
    public function arrayToOrderWasShipped(array $data): OrderWasShipped
    {
        return new OrderWasShipped(
            $data['orderId'],
            isset($data['fail']) ? (bool) $data['fail'] : false
        );
    }

    #[Converter]
    public function orderWasCancelledToArray(OrderWasCancelled $event): array
    {
        return [
            'orderId' => $event->orderId,
            'reason' => $event->reason,
            'fail' => $event->fail,
        ];
    }

    #[Converter]
    public function arrayToOrderWasCancelled(array $data): OrderWasCancelled
    {
        return new OrderWasCancelled(
            $data['orderId'],
            $data['reason'],
            isset($data['fail']) ? (bool) $data['fail'] : false
        );
    }

    #[Converter]
    public function orderWasReconfirmedToArray(OrderWasReconfirmed $event): array
    {
        return [
            'orderId' => $event->orderId,
        ];
    }

    #[Converter]
    public function arrayToOrderWasReconfirmed(array $data): OrderWasReconfirmed
    {
        return new OrderWasReconfirmed($data['orderId']);
    }

    #[Converter]
    public function orderWasReturnedToArray(OrderWasReturned $event): array
    {
        return [
            'orderId' => $event->orderId,
        ];
    }

    #[Converter]
    public function arrayToOrderWasReturned(array $data): OrderWasReturned
    {
        return new OrderWasReturned($data['orderId']);
    }
}
