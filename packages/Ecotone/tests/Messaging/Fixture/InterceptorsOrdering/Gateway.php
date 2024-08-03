<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\Parameter\Headers;

interface Gateway
{
    #[MessageGateway(requestChannel: "runEndpoint")]
    public function runWithReturn(#[Headers] $metadata = []): InterceptorOrderingStack;
}