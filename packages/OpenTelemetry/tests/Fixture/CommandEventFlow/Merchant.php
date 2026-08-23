<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Gateway\EventBus;

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
