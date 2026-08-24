<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Async;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\QueryHandler;
use stdClass;

/**
 * licence Apache-2.0
 */
class AsyncQueryHandlerExample
{
    #[Asynchronous('asyncChannel')]
    #[QueryHandler(endpointId: 'asyncEvent')]
    public function doSomething(stdClass $event): void
    {
    }
}
