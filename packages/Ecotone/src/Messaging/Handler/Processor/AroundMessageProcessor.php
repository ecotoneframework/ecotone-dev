<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInvocation;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;

class AroundMessageProcessor implements RealMessageProcessor
{
    public function __construct(
        private MessageProcessor $methodInvoker,
        private array $aroundInterceptors
    )
    {
    }

    public function process(Message $message): ?Message
    {
        $aroundMethodInvoker = new AroundMethodInvocation(
            $message,
            $this->aroundInterceptors,
            $this->methodInvoker,
        );

        $result = $aroundMethodInvoker->proceed();

        return $result;
    }
}