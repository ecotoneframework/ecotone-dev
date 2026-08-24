<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Async;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\ServiceActivator;

/**
 * licence Apache-2.0
 */
class AsyncMethodExample
{
    #[Asynchronous('asyncChannel')]
    #[ServiceActivator('inputChannel', 'asyncServiceActivator')]
    public function doSomething(): void
    {
    }
}
