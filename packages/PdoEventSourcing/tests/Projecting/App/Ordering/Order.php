<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering;

use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\CancelOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\PlaceOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command\ShipOrder;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasCancelled;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasPlaced;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasReconfirmed;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasReturned;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasShipped;

#[EventSourcingAggregate]
#[Stream(self::STREAM_NAME)]
#[AggregateType(self::AGGREGATE_TYPE)]
class Order
{
    use WithAggregateVersioning;
    public const STREAM_NAME = 'ordering';
    public const AGGREGATE_TYPE = 'order';
    #[Identifier]
    private string $orderId;
    private string $product;
    private int $quantity;
    private bool $shipped = false;
    private bool $cancelled = false;
    private ?string $cancelReason = null;

    #[CommandHandler]
    public static function place(PlaceOrder $command): array
    {
        return [new OrderWasPlaced($command->orderId, $command->product, $command->quantity, $command->fail)];
    }

    #[CommandHandler]
    public function ship(ShipOrder $command): array
    {
        if ($this->shipped) {
            return [];
        }
        $events[] = new OrderWasShipped($this->orderId, $command->fail);
        if ($this->cancelled) {
            $events[] = new OrderWasReconfirmed($this->orderId);
        }
        return $events;
    }

    #[CommandHandler]
    public function cancel(CancelOrder $command): array
    {
        if ($this->cancelled) {
            return [];
        }
        $events[] = new OrderWasCancelled($this->orderId, $command->reason, $command->fail);
        if ($this->shipped) {
            $events[] = new OrderWasReturned($this->orderId);
        }
        return $events;
    }

    #[EventSourcingHandler]
    public function onOrderWasPlaced(OrderWasPlaced $event): void
    {
        $this->orderId = $event->orderId;
        $this->product = $event->product;
        $this->quantity = $event->quantity;
    }

    #[EventSourcingHandler]
    public function onOrderWasShipped(OrderWasShipped $event): void
    {
        $this->shipped = true;
    }

    #[EventSourcingHandler]
    public function onOrderWasReturned(OrderWasReturned $event): void
    {
        $this->shipped = false;
    }

    #[EventSourcingHandler]
    public function onOrderWasCancelled(OrderWasCancelled $event): void
    {
        $this->cancelled = true;
        $this->cancelReason = $event->reason;
    }
}
