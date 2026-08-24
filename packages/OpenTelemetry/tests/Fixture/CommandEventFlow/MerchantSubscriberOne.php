<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

use Ecotone\Api\CommandBus;
use Ecotone\Api\EventHandler;

/**
 * licence Apache-2.0
 */
final class MerchantSubscriberOne
{
    #[EventHandler]
    public function merchantToUser(MerchantCreated $event, CommandBus $commandBus): void
    {
        $commandBus->send(new RegisterUser($event->merchantId));
    }
}
