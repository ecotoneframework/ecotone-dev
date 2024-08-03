<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

class GatewayInterceptors
{
    #[Around(pointcut: Gateway::class)]
    public function around(MethodInvocation $methodInvocation, InterceptorOrderingStack $stack, array $metadata): mixed
    {
        $stack->add("gateway::around begin", $metadata);
        $result = $methodInvocation->proceed();
        $stack->add("gateway::around end", $metadata, $result);
        return $result;
    }
}