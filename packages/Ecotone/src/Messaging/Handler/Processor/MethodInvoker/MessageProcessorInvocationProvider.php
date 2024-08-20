<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;

/**
 * @licence Apache-2.0
 */
class MessageProcessorInvocationProvider implements AroundInterceptable
{
    public function __construct(
        private MessageProcessor $messageProcessor,
    ) {
    }

    public function getObjectToInvokeOn(Message $message): string|object
    {
        return $this->messageProcessor;
    }

    public function getMethodName(): string
    {
        return 'process';
    }

    public function getArguments(Message $message): array
    {
        return [$message];
    }
}
