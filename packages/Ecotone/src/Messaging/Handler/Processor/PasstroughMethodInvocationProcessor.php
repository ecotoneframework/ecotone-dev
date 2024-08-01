<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodCallProvider;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;

class PasstroughMethodInvocationProcessor implements RealMessageProcessor
{
    public function __construct(
        private MethodCallProvider $methodCallProvider,
    )
    {
    }

    public function process(Message $message): ?Message
    {
        $methodInvocation = $this->methodCallProvider->getMethodInvocation($message);
        $methodInvocation->proceed();
        return $message;
    }
}