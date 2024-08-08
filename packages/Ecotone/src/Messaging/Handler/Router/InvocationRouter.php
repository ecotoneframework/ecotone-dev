<?php

namespace Ecotone\Messaging\Handler\Router;

use function array_unique;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocationProvider;
use Ecotone\Messaging\Message;

use function is_iterable;

/**
 * licence Apache-2.0
 */
class InvocationRouter implements RouteSelector
{
    public function __construct(private MethodInvocationProvider $methodCallProvider)
    {
    }

    public function route(Message $message): array
    {
        $result = $this->methodCallProvider->getMethodInvocation($message)->proceed();
        if (! is_iterable($result)) {
            $result = [$result];
        }
        return array_unique($result);
    }
}
