<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Async;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;
use stdClass;

#[Asynchronous('asyncChannel')]
/**
 * licence Apache-2.0
 */
class AsyncEventHandlerExample
{
    #[Asynchronous('asyncChannel')]
    #[EventHandler(endpointId: 'asyncEvent')]
    public function doSomething(stdClass $event): void
    {
    }
}
