<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use ArrayIterator;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Message;

/**
 * Executes endpoint with around interceptors
 *
 * Class MethodInvokerProcessor
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class AroundMethodInvocation implements MethodInvocation
{
    /**
     * @var ArrayIterator|AroundMethodInterceptor[]
     */
    private iterable $aroundMethodInterceptors;

    /**
     * @param AroundMethodInterceptor[] $aroundMethodInterceptors
     */
    public function __construct(
        private Message                        $requestMessage,
        array                                  $aroundMethodInterceptors,
        private MethodInvocation               $interceptedMethodInvocation,
    ) {
        $this->aroundMethodInterceptors = new ArrayIterator($aroundMethodInterceptors);
    }

    /**
     * @inheritDoc
     */
    public function proceed(): mixed
    {
        do {
            /** @var AroundMethodInterceptor $aroundMethodInterceptor */
            $aroundMethodInterceptor = $this->aroundMethodInterceptors->current();
            $this->aroundMethodInterceptors->next();

            if (! $aroundMethodInterceptor) {
                return $this->interceptedMethodInvocation->proceed();
            }

            $arguments = $aroundMethodInterceptor->getArguments(
                $this,
                $this->requestMessage
            );
            $referenceToCall = $aroundMethodInterceptor->getReferenceToCall();
            $methodName = $aroundMethodInterceptor->getMethodName();

            $returnValue = $referenceToCall->{$methodName}(...$arguments);
        } while (! $aroundMethodInterceptor->hasMethodInvocation());

        return $returnValue;
    }

    /**
     * @return mixed[]
     */
    public function getArguments(): array
    {
        return $this->interceptedMethodInvocation->getArguments();
    }

    public function getObjectToInvokeOn(): string|object
    {
        return $this->interceptedMethodInvocation->getObjectToInvokeOn();
    }

    public function getMethodName(): string
    {
        return $this->interceptedMethodInvocation->getMethodName();
    }

    public function getInterfaceToCall(): InterfaceToCall
    {
        return $this->interceptedMethodInvocation->getInterfaceToCall();
    }

    /**
     * @param string $parameterName
     * @param mixed $value
     * @return void
     */
    public function replaceArgument(string $parameterName, $value): void
    {
        $this->interceptedMethodInvocation->replaceArgument($parameterName, $value);
    }

    public function getName(): string
    {
        return $this->interceptedMethodInvocation->getName();
    }
}
