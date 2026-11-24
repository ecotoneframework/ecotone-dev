<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixtures\UnregisteredHandler;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;

/**
 * licence Apache-2.0
 */
#[Aggregate]
final class ShippingDepot
{
    public function __construct(#[Identifier] private string $depotId)
    {
    }

    #[CommandHandler('shipping.openDepot')]
    public static function open(string $depotId): self
    {
        return new self($depotId);
    }
}
