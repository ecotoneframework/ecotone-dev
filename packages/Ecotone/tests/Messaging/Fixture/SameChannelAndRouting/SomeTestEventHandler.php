<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\SameChannelAndRouting;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;

/**
 * licence Apache-2.0
 */
final class SomeTestEventHandler
{
    public int $handled = 0;

    #[Asynchronous('orders')]
    #[EventHandler(listenTo: 'orders', endpointId: 'test')]
    public function test2(): void
    {
        $this->handled++;
    }
}
