<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LegacyStream;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[Stream(legacyStreamName: self::LEGACY_STREAM_NAME)]
/**
 * licence Apache-2.0
 */
final class LegacyOrder
{
    use WithAggregateVersioning;

    public const LEGACY_STREAM_NAME = 'Test\Ecotone\EventSourcing\Fixture\LegacyStream\LegacyOrder';

    #[Identifier]
    private string $orderId;

    private string $status;

    #[CommandHandler('legacyOrder.place')]
    public static function place(PlaceLegacyOrder $command): array
    {
        return [new LegacyOrderPlaced($command->orderId)];
    }

    #[CommandHandler('legacyOrder.cancel')]
    public function cancel(CancelLegacyOrder $command): array
    {
        return [new LegacyOrderCancelled($this->orderId)];
    }

    #[QueryHandler('legacyOrder.getStatus')]
    public function getStatus(): string
    {
        return $this->status;
    }

    #[EventSourcingHandler]
    public function applyPlaced(LegacyOrderPlaced $event): void
    {
        $this->orderId = $event->orderId;
        $this->status = 'placed';
    }

    #[EventSourcingHandler]
    public function applyCancelled(LegacyOrderCancelled $event): void
    {
        $this->status = 'cancelled';
    }
}
