<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone\Config;

use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourced;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

class TransformPureAggregateStreamToAggregateInterceptor
{
    public function transform(MethodInvocation $methodInvocation, EventSourced $eventSourced): EventStream
    {
        $events = $methodInvocation->proceed();
        return new EventStream($eventSourced, $events);
    }
}