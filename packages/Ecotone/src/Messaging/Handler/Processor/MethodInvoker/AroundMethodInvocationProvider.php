<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Message;

/**
 * @licence Apache-2.0
 */
class AroundMethodInvocationProvider implements MethodInvocationProvider
{
    /**
     * @param AroundMethodInterceptor[] $aroundInterceptors
     */
    public function __construct(
        private MethodInvocationProvider $methodCallProvider,
        private array                    $aroundInterceptors,
    ) {
    }

    public function execute(Message $message): mixed
    {
        return $this->getMethodInvocation($message)->proceed();
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
