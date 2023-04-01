<?php

namespace Ecotone\Modelling\MessageHandling\MetadataPropagator;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Throwable;

class MessageHeadersPropagatorInterceptor
{
    private array $currentlyPropagatedHeaders = [];

    public function storeHeaders(MethodInvocation $methodInvocation, Message $message)
    {
        $userlandHeaders = MessageHeaders::unsetAllFrameworkHeaders($message->getHeaders()->headers());
        $userlandHeaders[MessageHeaders::MESSAGE_ID] = $message->getHeaders()->getMessageId();
        $userlandHeaders[MessageHeaders::MESSAGE_CORRELATION_ID] = $message->getHeaders()->getCorrelationId();
        $this->currentlyPropagatedHeaders[] = $userlandHeaders;

        try {
            $reply = $methodInvocation->proceed();
            array_shift($this->currentlyPropagatedHeaders);
        } catch (Throwable $exception) {
            array_shift($this->currentlyPropagatedHeaders);

            throw $exception;
        }

        return $reply;
    }

    public function propagateHeaders(array $headers): array
    {
        return MessageHeaders::propagateContextHeaders($this->getLastHeaders(), $headers);
    }

    public function getLastHeaders(): array
    {
        $headers = end($this->currentlyPropagatedHeaders);

        if ($this->isCalledForFirstTime($headers)) {
            return [];
        }

        return $headers;
    }

    private function isCalledForFirstTime($headers): bool
    {
        return $headers === false;
    }
}
