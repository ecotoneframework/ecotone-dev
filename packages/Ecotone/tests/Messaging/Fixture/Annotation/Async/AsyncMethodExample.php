<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Async;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\InternalHandler;

/**
 * licence Apache-2.0
 */
class AsyncMethodExample
{
    #[Asynchronous('asyncChannel')]
    #[InternalHandler('inputChannel', endpointId: 'asyncServiceActivator')]
    public function doSomething(): void
    {
    }
}
