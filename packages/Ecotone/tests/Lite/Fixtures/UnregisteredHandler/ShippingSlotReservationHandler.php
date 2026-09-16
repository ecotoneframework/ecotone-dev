<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixtures\UnregisteredHandler;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;
use Test\Ecotone\Lite\Fixtures\UnregisteredHandler\Command\ReserveShippingSlot;

/**
 * licence Apache-2.0
 */
final class ShippingSlotReservationHandler
{
    #[CommandHandler]
    public function reserve(ReserveShippingSlot $command): void
    {
    }

    #[CommandHandler('shipping.cancelSlot')]
    public function cancel(string $orderId): void
    {
    }

    #[QueryHandler('shipping.getSlot')]
    public function getSlot(string $orderId): string
    {
        return 'slot';
    }
}
