<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;

/**
 * @licence Apache-2.0
 */
class MessageProcessorInvocationProvider implements MethodCallProvider
{
    public function __construct(
        private MessageProcessor $messageProcessor,
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
