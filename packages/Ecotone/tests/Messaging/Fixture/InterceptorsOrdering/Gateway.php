<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\Parameter\Headers;

interface Gateway
{
    #[MessageGateway(requestChannel: "serviceEndpointReturning")]
    public function runWithReturn(): string;

    #[MessageGateway(requestChannel: "serviceEndpointVoid")]
    public function runWithVoid(): void;
}