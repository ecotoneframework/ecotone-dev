<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;

/**
 * This class provides ability to call after interceptors inside method invocation
 */
class MethodInvocationWithChainedAfterInterceptorsProvider implements MethodCallProvider
{
    public function __construct(
        private MethodCallProvider $methodCallProvider,
        private MessageProcessor   $afterMessageProcessor,
    ) {
    }

    public function getMethodInvocation(Message $message): MethodInvocation
    {
        return new MethodInvocationWithChainedAfterInterceptors(
            $message,
            $this->methodCallProvider->getMethodInvocation($message),
            $this->afterMessageProcessor,
        );
    }
}
