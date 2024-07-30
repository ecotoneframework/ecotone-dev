<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;

class PasstroughMethodInvocationProcessor implements RealMessageProcessor
{
    public function __construct(
        private MethodInvoker $methodInvoker
    )
    {
    }

    public function process(Message $message): ?Message
    {
        $params = $this->methodInvoker->getMethodCall($message)->getMethodArgumentValues();
        $objectToInvokeOn = $this->methodInvoker->getObjectToInvokeOn();
        is_string($objectToInvokeOn)
            ? $objectToInvokeOn::{$this->methodInvoker->getMethodName()}(...$params)
            : $objectToInvokeOn->{$this->methodInvoker->getMethodName()}(...$params);

        return $message;
    }
}