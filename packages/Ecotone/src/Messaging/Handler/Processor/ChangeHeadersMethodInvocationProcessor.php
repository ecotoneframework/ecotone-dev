<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodCallProvider;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

class ChangeHeadersMethodInvocationProcessor implements RealMessageProcessor
{
    public function __construct(
        private MethodCallProvider $methodCallProvider,
        private string $interfaceToCallName,
    )
    {
    }
    public function process(Message $message): ?Message
    {
        $result = $this->methodCallProvider->getMethodInvocation($message)->proceed();

        if (is_null($result)) {
            return null;
        }

        Assert::isFalse($result instanceof Message, 'Message should not be returned when changing headers in ' . $this->interfaceToCallName);
        Assert::isTrue(is_array($result), 'Result should be an array when changing headers in ' . $this->interfaceToCallName);

        return MessageBuilder::fromMessage($message)
            ->setMultipleHeaders($result)
            ->build();
    }
}