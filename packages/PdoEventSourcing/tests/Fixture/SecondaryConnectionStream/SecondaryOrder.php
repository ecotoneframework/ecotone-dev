<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Api\QueryHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[Stream(self::STREAM, connectionReferenceName: self::CONNECTION_REFERENCE)]
/**
 * licence Apache-2.0
 */
final class SecondaryOrder
{
    use WithAggregateVersioning;

    public const STREAM = 'secondary_connection_stream';
    public const CONNECTION_REFERENCE = 'secondary_connection';

    #[Identifier]
    private string $orderId;

    private string $status;

    #[CommandHandler('secondaryOrder.place')]
    public static function place(PlaceSecondaryOrder $command): array
    {
        return [new SecondaryOrderPlaced($command->orderId)];
    }

    #[CommandHandler('secondaryOrder.cancel')]
    public function cancel(CancelSecondaryOrder $command): array
    {
        return [new SecondaryOrderCancelled($this->orderId)];
    }

    #[QueryHandler('secondaryOrder.getStatus')]
    public function getStatus(): string
    {
        return $this->status;
    }

    #[EventSourcingHandler]
    public function applyPlaced(SecondaryOrderPlaced $event): void
    {
        $this->orderId = $event->orderId;
        $this->status = 'placed';
    }

    #[EventSourcingHandler]
    public function applyCancelled(SecondaryOrderCancelled $event): void
    {
        $this->status = 'cancelled';
    }
}
