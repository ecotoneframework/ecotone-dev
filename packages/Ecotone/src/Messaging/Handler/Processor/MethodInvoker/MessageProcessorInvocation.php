<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;
use InvalidArgumentException;

/**
 * @licence Apache-2.0
 */
class MessageProcessorInvocation implements MethodInvocation
{
    public function __construct(
        private MessageProcessor $messageProcessor,
        private Message          $message,
    ) {
    }

    public function proceed(): ?Message
    {
        return $this->messageProcessor->process($this->message);
    }

    public function getObjectToInvokeOn(): string|object
    {
        return $this->messageProcessor;
    }

    public function getMethodName(): string
    {
        return 'process';
    }

    public function getInterfaceToCall(): InterfaceToCall
    {
        throw new InvalidArgumentException('Not supported');
    }

    public function getName(): string
    {
        return MessageProcessor::class . '::process';
    }

    public function getArguments(): array
    {
        return [$this->message];
    }

    public function replaceArgument(string $parameterName, $value): void
    {
        throw new InvalidArgumentException('Not supported');
    }
}
