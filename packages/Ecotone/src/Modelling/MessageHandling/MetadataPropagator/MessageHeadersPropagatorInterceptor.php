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
        $headers = $message->getHeaders()->headers();
        $headers = MessageHeaders::unsetFrameworkKeys($headers);
        $headers = MessageHeaders::unsetNonUserKeys($headers);

        $this->currentlyPropagatedHeaders[] = $headers;

        try {
            $reply = $methodInvocation->proceed();
        } finally {
            array_shift($this->currentlyPropagatedHeaders);
        }

        return $reply;
    }

    public function propagateHeaders(array $headers): array
    {
        try{
            return array_merge($this->getLastHeaders(), $headers);
        } finally {
            array_shift($this->currentlyPropagatedHeaders);
        }
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
