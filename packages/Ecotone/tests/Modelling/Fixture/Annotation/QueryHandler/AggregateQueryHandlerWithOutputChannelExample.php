<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler;

use Ecotone\Api\Aggregate;
use Ecotone\Api\Identifier;
use Ecotone\Api\QueryHandler;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateQueryHandlerWithOutputChannelExample
{
    #[Identifier]
    private string $id;

    #[QueryHandler(endpointId: 'some-id', outputChannelName: 'outputChannel')]
    public function doStuff(SomeQuery $query): SomeResult
    {
        return new SomeResult();
    }
}
