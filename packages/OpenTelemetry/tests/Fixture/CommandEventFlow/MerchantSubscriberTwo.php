<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\CommandBus;

/**
 * licence Apache-2.0
 */
final class MerchantSubscriberTwo
{
    #[EventHandler]
    public function merchantToUser(MerchantCreated $event, CommandBus $commandBus): void
    {
        $commandBus->send(new RegisterUser($event->merchantId));
    }
}
