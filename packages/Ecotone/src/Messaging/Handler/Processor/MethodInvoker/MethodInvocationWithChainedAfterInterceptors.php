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
    /**
     * @param array<RealMessageProcessor> $afterMethodMessageProcessors
     */
    public function __construct(
        private Message $message,
        private MethodInvocation $methodInvocation,
        private RealMessageProcessor $afterMethodMessageProcessor
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
        } else {
            return $resultMessage->getPayload();
        }
    }

    public function getObjectToInvokeOn(): string|object
    {
        // TODO: Implement getObjectToInvokeOn() method.
    }

    public function getMethodName(): string
    {
        // TODO: Implement getMethodName() method.
    }

    public function getInterfaceToCall(): InterfaceToCall
    {
        // TODO: Implement getInterfaceToCall() method.
    }

    public function getName(): string
    {
        // TODO: Implement getName() method.
    }

    public function getArguments(): array
    {
        // TODO: Implement getArguments() method.
    }

    public function replaceArgument(string $parameterName, $value): void
    {
        // TODO: Implement replaceArgument() method.
    }
}