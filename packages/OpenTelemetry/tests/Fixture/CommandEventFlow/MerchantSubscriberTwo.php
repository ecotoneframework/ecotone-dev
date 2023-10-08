<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\CommandBus;

final class MerchantSubscriberTwo
{
    #[EventHandler]
    public function merchantToUser(MerchantCreated $event, CommandBus $commandBus): void
    {
        $commandBus->send(new RegisterUser($event->merchantId));
    }
}
