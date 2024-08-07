<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\SameChannelAndRouting;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

/**
 * licence Apache-2.0
 */
final class SomeTestEventHandler
{
    #[Asynchronous('input')]
    #[EventHandler(listenTo: 'input', endpointId: 'test')]
    public function test2(): void
    {
    }
}
