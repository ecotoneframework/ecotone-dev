<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

class PollingConsumerPostSendAroundInterceptor
{
    public function __construct(private PollingConsumerContext $pollingConsumerContext)
    {
    }

    public function postSend(MethodInvocation $methodInvocation): mixed
    {
        foreach ($this->pollingConsumerContext->getPollingConsumerInterceptors() as $interceptor) {
            $interceptor->postSend();
        }
        return $methodInvocation->proceed();
    }
}