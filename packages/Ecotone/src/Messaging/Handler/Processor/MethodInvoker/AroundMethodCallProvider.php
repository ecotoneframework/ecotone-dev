<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Message;

/**
 * @licence Apache-2.0
 */
class AroundMethodCallProvider implements MethodCallProvider
{
    /**
     * @param AroundMethodInterceptor[] $aroundInterceptors
     */
    public function __construct(
        private MethodCallProvider             $methodCallProvider,
        private array                          $aroundInterceptors,
    ) {
    }

    public function getMethodInvocation(Message $message): MethodInvocation
    {
        return new AroundMethodInvocation(
            $message,
            $this->aroundInterceptors,
            $this->methodCallProvider->getMethodInvocation($message),
        );
    }
}
