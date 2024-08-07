<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;

class MessageProcessorInvocationProvider implements MethodCallProvider
{
    public function __construct(
        private RealMessageProcessor $messageProcessor,
    ) {
    }

    public function getMethodInvocation(Message $message): MethodInvocation
    {
        return new MessageProcessorInvocation(
            $this->messageProcessor,
            $message,
        );
    }
}
