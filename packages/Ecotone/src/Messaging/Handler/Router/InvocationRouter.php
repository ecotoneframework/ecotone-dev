<?php

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodCallProvider;
use Ecotone\Messaging\Message;

class InvocationRouter implements RouteSelector
{
    public function __construct(private MethodCallProvider $methodCallProvider)
    {
    }

    public function route(Message $message): array
    {
        $result = $this->methodCallProvider->getMethodInvocation($message)->proceed();
        if (! \is_iterable($result)) {
            $result = [$result];
        }
        return \array_unique($result);
    }
}