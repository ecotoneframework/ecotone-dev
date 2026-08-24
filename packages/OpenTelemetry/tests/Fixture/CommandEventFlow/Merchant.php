<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventBus;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class Merchant
{
    #[Identifier]
    private string $merchantId;

    #[CommandHandler]
    public static function create(CreateMerchant $command, EventBus $eventBus): self
    {
        $self = new self();
        $self->merchantId = $command->merchantId;

        $eventBus->publish(new MerchantCreated($command->merchantId));

        return $self;
    }
}
