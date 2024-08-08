<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocationProvider;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\ResultToMessageConverter;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
class MethodInvocationProcessor implements MessageProcessor
{
    public function __construct(
        private MethodInvocationProvider $methodCallProvider,
        private ResultToMessageConverter $resultToMessageBuilder,
    ) {
    }

    public function process(Message $message): ?Message
    {
        $result = $this->methodCallProvider->getMethodInvocation($message)->proceed();

        return $this->resultToMessageBuilder->convertToMessage($message, $result);
    }
}
