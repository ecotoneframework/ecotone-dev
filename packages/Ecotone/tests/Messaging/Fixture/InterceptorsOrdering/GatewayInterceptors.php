<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\Interceptor\After;
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

class GatewayInterceptors
{
    #[Around(pointcut: Gateway::class)]
    public function around(MethodInvocation $methodInvocation, #[Headers] array $metadata): mixed
    {
        $stack = $metadata["stack"];
        $stack->add("gateway::around begin", $metadata);
        $result = $methodInvocation->proceed();
        $stack->add("gateway::around end", $metadata, $result);
        return $result;
    }

    #[Before(pointcut: Gateway::class)]
    public function before(#[Headers] array $metadata): void
    {
        $stack = $metadata["stack"];
        $stack->add("gateway::before", $metadata);
    }

    #[After(pointcut: Gateway::class)]
    public function after(#[Headers] array $metadata): void
    {
        $stack = $metadata["stack"];
        $stack->add("gateway::after", $metadata);
    }
}