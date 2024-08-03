<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;

class AroundMethodCallProvider implements MethodCallProvider
{
    /**
     * @param AroundMethodInterceptor[] $aroundInterceptors
     */
    public function __construct(
        private MethodCallProvider             $methodCallProvider,
        private array                          $aroundInterceptors,
        private ResultToMessageConverter       $resultToMessageConverter,
        private ?RealMessageProcessor          $afterProcessor = null,
    )
    {
    }

    public function getMethodInvocation(Message $message): MethodInvocation
    {
        return new AroundMethodInvocation(
            $message,
            $this->aroundInterceptors,
            $this->methodCallProvider->getMethodInvocation($message),
            $this->resultToMessageConverter,
            $this->afterProcessor
        );
    }
}