<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Interceptor;

use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

final class CustomDistributedBusInterceptor
{
    public bool $wasCalled = false;

    #[Around(pointcut: AsynchronousRunningEndpoint::class)]
    public function onConsumption(MethodInvocation $methodInvocation): mixed
    {
        $this->wasCalled = true;

        return $methodInvocation->proceed();
    }
}