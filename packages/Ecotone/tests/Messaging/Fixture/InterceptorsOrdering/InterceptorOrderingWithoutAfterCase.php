<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\Interceptor\After;
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Modelling\Attribute\CommandHandler;

class InterceptorOrderingWithoutAfterCase
{
    const POINTCUT = self::class . "::endpoint";

    #[Before(precedence: -1, pointcut: self::POINTCUT, changeHeaders: true)]
    public function beforeChangeHeaders(InterceptorOrderingStack $stack, array $metadata): array
    {
        $stack->add("beforeChangeHeaders", $metadata);
        return [...$metadata, "beforeChangeHeaders" => "header"];
    }

    #[Before(pointcut: self::POINTCUT)]
    public function before(InterceptorOrderingStack $stack, array $metadata): InterceptorOrderingStack
    {
        return $stack->add("before", $metadata);
    }

    #[Around(pointcut: self::POINTCUT)]
    public function around(MethodInvocation $methodInvocation, InterceptorOrderingStack $stack, array $metadata): mixed
    {
        $stack->add("around begin", $metadata);
        $result = $methodInvocation->proceed();
        $stack->add("around end", $metadata, $result);
        return $result;
    }

    #[CommandHandler(routingKey: "endpoint")]
    public function endpoint(InterceptorOrderingStack $stack, array $metadata): InterceptorOrderingStack
    {
        $stack->add("endpoint", $metadata);
        return $stack;
    }
}