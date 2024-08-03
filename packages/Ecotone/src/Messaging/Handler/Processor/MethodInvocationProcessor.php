<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodCallProvider;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodResultToMessageConverter;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Handler\UnionTypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

class MethodInvocationProcessor implements RealMessageProcessor
{
    public function __construct(
        private MethodCallProvider             $methodCallProvider,
        private MethodResultToMessageConverter $resultToMessageBuilder,
    )
    {
    }

    public function process(Message $message): ?Message
    {
        $result = $this->methodCallProvider->getMethodInvocation($message)->proceed();

        return $this->resultToMessageBuilder->convertToMessage($message, $result);
    }
}