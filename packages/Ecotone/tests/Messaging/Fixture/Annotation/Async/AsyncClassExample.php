<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Async;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\ServiceActivator;

#[Asynchronous(channelName: 'asyncChannel2')]
/**
 * licence Apache-2.0
 */
class AsyncClassExample
{
    #[ServiceActivator('inputChannel', 'asyncServiceActivator2')]
    public function doSomething2(): void
    {
    }

    #[Asynchronous('asyncChannel1')]
    #[ServiceActivator('inputChannel', 'asyncServiceActivator1')]
    public function doSomething1(): void
    {
    }
}
