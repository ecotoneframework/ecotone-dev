<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\CommandEventFlow;

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

    #[CommandHandler('create.merchant')]
    #[CommandHandler]
    public static function create(CreateMerchant $command, EventBus $eventBus): self
    {
        $self = new self();
        $self->merchantId = $command->merchantId;

        $eventBus->publish(new MerchantCreated($command->merchantId));

        return $self;
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }
}
