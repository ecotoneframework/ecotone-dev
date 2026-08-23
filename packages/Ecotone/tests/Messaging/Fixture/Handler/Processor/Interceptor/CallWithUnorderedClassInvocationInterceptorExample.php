<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor;

use Ecotone\Api\Attribute\ClassReference;
use Ecotone\Api\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use stdClass;

#[ClassReference('callWithUnordered')]
/**
 * licence Apache-2.0
 */
class CallWithUnorderedClassInvocationInterceptorExample extends BaseInterceptorExample
{
    #[Around]
    public function callWithUnorderedClassInvocation(MethodInvocation $methodInvocation, int $test, stdClass $stdClass)
    {
        return $methodInvocation->proceed();
    }
}
