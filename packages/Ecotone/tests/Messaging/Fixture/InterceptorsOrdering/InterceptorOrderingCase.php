<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Modelling\Attribute\CommandHandler;

class InterceptorOrderingCase
{
    #[CommandHandler(routingKey: "endpoint")]
    public function endpoint(#[Headers] array $metadata): InterceptorOrderingStack
    {
        $stack = $metadata["stack"];
        $stack->add("endpoint", $metadata);
        return $stack;
    }

    #[ServiceActivator(inputChannelName: "runEndpoint")]
    public function serviceActivator(#[Headers] array $metadata): InterceptorOrderingStack
    {
        $stack = $metadata["stack"];
        $stack->add("endpoint", $metadata);
        return $stack;
    }
}