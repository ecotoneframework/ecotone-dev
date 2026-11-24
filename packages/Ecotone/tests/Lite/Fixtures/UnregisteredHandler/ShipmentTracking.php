<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixtures\UnregisteredHandler;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Saga;

/**
 * licence Apache-2.0
 */
#[Saga]
final class ShipmentTracking
{
    public function __construct(#[Identifier] private string $shipmentId)
    {
    }

    #[CommandHandler('shipping.startTracking')]
    public static function start(string $shipmentId): self
    {
        return new self($shipmentId);
    }
}
