<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use ArrayIterator;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;

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

    private MethodCall $methodCall;

    /**
     * @param AroundMethodInterceptor[] $aroundMethodInterceptors
     */
    public function __construct(
        private Message          $requestMessage,
        array                    $aroundMethodInterceptors,
        private MessageProcessor $interceptedMessageProcessor,
    ) {
        $this->aroundMethodInterceptors = new ArrayIterator($aroundMethodInterceptors);
        // If we are sending an in-process message in an interceptor
        // send it immediately
        $this->requestMessage = MessageBuilder::fromMessage($requestMessage)
            ->setHeader(MessageHeaders::IN_PROCESS_EXECUTOR_INTERCEPTING, true)
            ->build();
        $this->methodCall = $interceptedMessageProcessor->getMethodCall($this->requestMessage);
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
                $message = MessageBuilder::fromMessage($this->requestMessage)
                    ->removeHeader(MessageHeaders::IN_PROCESS_EXECUTOR_INTERCEPTING)
                    ->removeHeader(MessageHeaders::IN_PROCESS_EXECUTOR)
                    ->build();
                return $this->interceptedMessageProcessor->executeEndpoint($message);
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
        return $this->methodCall->getMethodArgumentValues();
    }

    public function getObjectToInvokeOn(): string|object
    {
        return $this->interceptedMessageProcessor->getObjectToInvokeOn();
    }

    public function getMethodName(): string
    {
        return $this->interceptedMessageProcessor->getMethodName();
    }

    public function getInterfaceToCall(): InterfaceToCall
    {
        return InterfaceToCall::create($this->getObjectToInvokeOn(), $this->getMethodName());
    }

    /**
     * @param string $parameterName
     * @param mixed $value
     * @return void
     */
    public function replaceArgument(string $parameterName, $value): void
    {
        $this->methodCall->replaceArgument($parameterName, $value);
    }

    public function getName(): string
    {
        $object = $this->getObjectToInvokeOn();
        $classname = is_string($object) ? $object : get_class($object);
        return "{$classname}::{$this->getMethodName()}";
    }
}
