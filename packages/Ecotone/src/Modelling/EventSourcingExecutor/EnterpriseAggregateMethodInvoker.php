<?php

declare(strict_types=1);

namespace Ecotone\Modelling\EventSourcingExecutor;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\StaticMethodCallProvider;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\EventSourcingHandlerMethod;

/**
 * licence Enterprise
 */
final class EnterpriseAggregateMethodInvoker implements AggregateMethodInvoker
{
    public function executeMethod(mixed $aggregate, EventSourcingHandlerMethod $eventSourcingHandler, Message $message): void
    {
        (new StaticMethodCallProvider(
            $aggregate,
            $eventSourcingHandler->getMethodName(),
            $eventSourcingHandler->getParameterConverters(),
            $eventSourcingHandler->getInterfaceParametersNames(),
        ))->getMethodInvocation($message)->proceed();
    }
}
