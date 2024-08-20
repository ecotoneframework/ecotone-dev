<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Message;

/**
 * @licence Apache-2.0
 */
class AroundMethodInvoker implements MethodInvoker
{
    /**
     * @param AroundMethodInterceptor[] $aroundInterceptors
     */
    public function __construct(
        private AroundInterceptable $methodCallProvider,
        private array                    $aroundInterceptors,
    ) {
    }

    public function execute(Message $message): mixed
    {
        $invocation = new AroundMethodInvocation(
            $message,
            $this->aroundInterceptors,
            $this->methodCallProvider,
        );
        return $invocation->proceed();
    }
}
