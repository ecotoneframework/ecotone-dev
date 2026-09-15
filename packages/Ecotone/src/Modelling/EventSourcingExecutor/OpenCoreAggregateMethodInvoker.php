<?php

declare(strict_types=1);

namespace Ecotone\Modelling\EventSourcingExecutor;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\EventSourcingHandlerMethod;

/**
 * licence Apache-2.0
 */
final class OpenCoreAggregateMethodInvoker implements AggregateMethodInvoker
{
    public function executeMethod(mixed $aggregate, EventSourcingHandlerMethod $eventSourcingHandler, Message $message): void
    {
        if ($eventSourcingHandler->parametersCount() > 1) {
            throw InvalidArgumentException::create("Using multiple parameters for Event Sourcing Handler: {$eventSourcingHandler} is part of Enterprise features. Without Enterprise, keep a single event parameter and carry the value you need (for example the time of the change) in the event itself. To read metadata here, obtain Enterprise: https://docs.ecotone.tech/enterprise");
        }

        $aggregate->{$eventSourcingHandler->getMethodName()}($message->getPayload());
    }
}
