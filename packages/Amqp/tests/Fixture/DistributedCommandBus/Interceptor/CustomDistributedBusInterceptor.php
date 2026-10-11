<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Interceptor;

use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Interceptor\MethodInvocation;

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
