<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use ArrayIterator;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Handler\RequestReplyProducer;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * Class MethodInvokerProcessor
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class MethodInvokerChainProcessor implements MethodInvocation
{
    /**
     * @var ArrayIterator|AroundMethodInterceptor[]
     */
    private iterable $aroundMethodInterceptors;

    private QueueChannel $bridge;

    private Message $requestMessage;

    public function __construct(
        private MessageProcessor $messageProcessor,
        private MethodCall $methodCall, array $aroundMethodInterceptors,
        Message $requestMessage, private RequestReplyProducer $requestReplyProducer
    )
    {
        $this->aroundMethodInterceptors = new ArrayIterator($aroundMethodInterceptors);
        /**
         * This will ensure that after all connected output channels finish, we can fetch the result
         * and pass it to origin reply channel
         */
        $this->bridge = QueueChannel::create();
        $this->requestMessage = MessageBuilder::fromMessage($requestMessage)
            ->setReplyChannel($this->bridge)
            ->build();
    }

    public function beginTheChain(): void
    {
        $bridge = QueueChannel::create();
        $previousReplyChannel = $this->requestMessage->getHeaders()->containsKey(MessageHeaders::REPLY_CHANNEL) ? $this->requestMessage->getHeaders()->getReplyChannel() : null;

        /**
         * This will ensure that after all connected output channels finish, we can fetch the result
         * and pass it to origin reply channel
         */
        $this->requestMessage = MessageBuilder::fromMessage($this->requestMessage)
            ->setReplyChannel($bridge)
            ->build();

        $result = $this->proceed();
        if ($previousReplyChannel && $result) {
            $previousReplyChannel->send($result);
        }
    }

    /**
     * @inheritDoc
     */
    public function proceed()
    {
        /** @var AroundMethodInterceptor $aroundMethodInterceptor */
        $aroundMethodInterceptor = $this->aroundMethodInterceptors->current();
        $this->aroundMethodInterceptors->next();

        if (! $aroundMethodInterceptor) {
            $bridge = $this->requestMessage->getHeaders()->getReplyChannel();
            $message = MessageBuilder::fromMessage($this->requestMessage)
                ->setReplyChannel($bridge)
                ->build();

            $this->requestReplyProducer->executeEndpointAndSendReply($this->methodCall, $message);

            return $bridge->receive();
        }

        return $aroundMethodInterceptor->invoke(
            $this,
            $this->methodCall,
            $this->requestMessage
        );
    }

    /**
     * @return array
     */
    public function getArguments(): array
    {
        return $this->methodCall->getArguments();
    }

    /**
     * @var string|object
     */
    public function getObjectToInvokeOn()
    {
        return $this->messageProcessor->getObjectToInvokeOn();
    }

    /**
     * @return string
     */
    public function getInterceptedClassName(): string
    {
        return $this->messageProcessor->getInterceptedInterface()->getInterfaceType()->toString();
    }

    /**
     * @return string
     */
    public function getInterceptedMethodName(): string
    {
        return $this->messageProcessor->getInterceptedInterface()->getMethodName();
    }

    /**
     * @return InterfaceToCall
     */
    public function getInterceptedInterface(): InterfaceToCall
    {
        return $this->messageProcessor->getInterceptedInterface();
    }

    /**
     * @return object[]
     */
    public function getEndpointAnnotations(): iterable
    {
        return $this->messageProcessor->getEndpointAnnotations();
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
}
