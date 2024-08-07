<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocationProvider;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocationImplementation;
use Ecotone\Messaging\Message;

class AggregateMethodInvocationProvider implements MethodInvocationProvider
{
    public function __construct(
        private string $aggregateClass,
        private string $methodName,
        private array $methodParameterConverters,
        private array $methodParameterNames,
    ) {
    }

    public function getMethodInvocation(Message $message): MethodInvocation
    {
        $calledAggregate = $message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_OBJECT) ? $message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_OBJECT) : null;

        $methodArguments = [];
        $count = count($this->methodParameterConverters);

        for ($index = 0; $index < $count; $index++) {
            $parameterName = $this->methodParameterNames[$index];
            $data = $this->methodParameterConverters[$index]->getArgumentFrom($message);

            $methodArguments[$parameterName] = $data;
        }

        return new MethodInvocationImplementation(
            $calledAggregate ?: $this->aggregateClass,
            $this->methodName,
            $methodArguments,
        );
    }
}
