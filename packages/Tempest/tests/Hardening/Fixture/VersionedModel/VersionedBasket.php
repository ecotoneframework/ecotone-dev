<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\VersionedModel;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Version;
use Tempest\Database\IsDatabaseModel;

/**
 * licence Apache-2.0
 */
#[Aggregate]
final class VersionedBasket
{
    use IsDatabaseModel;

    #[Identifier]
    public string $basketId;

    #[Version]
    public int $version = 0;

    #[CommandHandler('versioned_basket.open')]
    public static function open(string $basketId): self
    {
        $basket = new self();
        $basket->basketId = $basketId;

        return $basket;
    }
}
