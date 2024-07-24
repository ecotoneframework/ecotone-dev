<?php

namespace Ecotone\Modelling\AggregateFlow\CallAggregate;

use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInvocation;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\AggregateMessage;

class AggregateMethodInvoker
{

    /**
     * @param array<ParameterConverter> $methodParameterConverters
     * @param array<string> $methodParameterNames
     * @param array<AroundMethodInterceptor> $aroundMethodInterceptors
     */
    public function __construct(
        private string $aggregateClass,
        private string $objectMethodName,
        private array $methodParameterNames,
        private array $methodParameterConverters,
        private array $aroundMethodInterceptors
    ) {
        Assert::allInstanceOfType($methodParameterConverters, ParameterConverter::class);
        Assert::allInstanceOfType($aroundMethodInterceptors, AroundMethodInterceptor::class);
    }

    public function execute(Message $message): mixed
    {
        $calledAggregate = $message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_OBJECT) ? $message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_OBJECT) : null;

        $methodInvoker = new MethodInvoker(
            objectToInvokeOn: $calledAggregate ?: $this->aggregateClass,
            objectMethodName: $this->objectMethodName,
            methodParameterConverters: $this->methodParameterConverters,
            methodParameterNames: $this->methodParameterNames,
            canInterceptorReplaceArguments: false
        );

        if ($this->aroundMethodInterceptors) {
            $methodInvokerChainProcessor = new AroundMethodInvocation($message, $this->aroundMethodInterceptors, $methodInvoker);
            return $methodInvokerChainProcessor->proceed();
        } else {
            return $methodInvoker->executeEndpoint($message);
        }
    }
}