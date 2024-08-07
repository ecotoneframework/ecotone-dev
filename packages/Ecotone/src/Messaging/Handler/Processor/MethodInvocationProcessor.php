<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodCallProvider;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\ResultToMessageConverter;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;

class MethodInvocationProcessor implements MessageProcessor
{
    public function __construct(
        private MethodCallProvider             $methodCallProvider,
        private ResultToMessageConverter $resultToMessageBuilder,
    ) {
    }

    public function process(Message $message): ?Message
    {
        $result = $this->methodCallProvider->getMethodInvocation($message)->proceed();

        return $this->resultToMessageBuilder->convertToMessage($message, $result);
    }
}
