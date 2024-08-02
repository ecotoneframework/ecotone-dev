<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * This class provides ability to call after interceptors inside method invocation
 */
class MethodInvocationWithChainedAfterInterceptors implements MethodInvocation
{
    public function __construct(
        private Message $message,
        private MethodInvocation $methodInvocation,
        private RealMessageProcessor $afterMethodMessageProcessor,
    )
    {
    }

    public function proceed(): mixed
    {
        $result = $this->methodInvocation->proceed();

        // This is from AroundInterceptorHandler
        if (\is_null($result)) {
            return null;
        }

        $message = $result instanceof Message ? $result : MessageBuilder::fromMessage($this->message)->setPayload($result)->build();

        $resultMessage = $this->afterMethodMessageProcessor->process($message);

        if (is_null($resultMessage)) {
            return null;
        } else if($result instanceof Message) {
            return $resultMessage;
        } else {
            return $resultMessage->getPayload();
        }
    }

    public function getObjectToInvokeOn(): string|object
    {
        return $this->methodInvocation->getObjectToInvokeOn();
    }

    public function getMethodName(): string
    {
        return $this->methodInvocation->getMethodName();
    }

    public function getInterfaceToCall(): InterfaceToCall
    {
        return $this->methodInvocation->getInterfaceToCall();
    }

    public function getName(): string
    {
        return $this->methodInvocation->getName();
    }

    public function getArguments(): array
    {
        return $this->methodInvocation->getArguments();
    }

    public function replaceArgument(string $parameterName, $value): void
    {
        $this->methodInvocation->replaceArgument($parameterName, $value);
    }
}