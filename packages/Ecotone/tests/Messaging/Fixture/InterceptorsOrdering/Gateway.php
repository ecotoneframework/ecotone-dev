<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\MessageGateway;

interface Gateway
{
    #[MessageGateway(requestChannel: "runEndpoint")]
    public function runWithReturn(InterceptorOrderingStack $stack): InterceptorOrderingStack;

    #[MessageGateway(requestChannel: "runEndpoint")]
    public function runWithVoid(InterceptorOrderingStack $stack): void;
}