<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\SameChannelAndRouting;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;

/**
 * licence Apache-2.0
 */
final class SomeTestCommandHandler
{
    public int $handled = 0;

    #[Asynchronous('orders')]
    #[CommandHandler(routingKey: 'orders', endpointId: 'test')]
    public function test(): void
    {
        $this->handled++;
    }
}
