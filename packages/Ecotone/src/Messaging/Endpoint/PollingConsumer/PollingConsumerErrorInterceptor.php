<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\ErrorMessage;
use Throwable;

class PollingConsumerErrorInterceptor
{
    public function __construct(private ChannelResolver $channelResolver)
    {
    }

    public function handle(MethodInvocation $methodInvocation, Message $requestMessage, ?PollingMetadata $pollingMetadata)
    {
        try {
            return $methodInvocation->proceed();
        } catch (Throwable $exception) {
            if ($errorChannelName = $pollingMetadata?->getErrorChannelName()) {
                $errorChannel = $this->channelResolver->resolve($errorChannelName);
                $errorChannel->send(ErrorMessage::create(MessageHandlingException::fromOtherException($exception, $requestMessage)));
                return null;
            } else {
                throw $exception;
            }
        }
    }
}