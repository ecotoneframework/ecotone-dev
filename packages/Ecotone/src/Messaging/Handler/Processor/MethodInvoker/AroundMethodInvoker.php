<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use ArrayIterator;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Handler\RequestReplyProducer;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Ramsey\Uuid\Uuid;

/**
 * Executes endpoint with around interceptors
 *
 * Class MethodInvokerProcessor
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class AroundMethodInvoker implements MethodInvocation
{
    /**
     * @var ArrayIterator|AroundMethodInterceptor[]
     */
    private iterable $aroundMethodInterceptors;

    public function __construct(
        private MessageProcessor $messageProcessor,
        private MethodCall $methodCall,
        array $aroundMethodInterceptors,
        private Message $requestMessage,
        private RequestReplyProducer $requestReplyProducer
    ) {
        $this->aroundMethodInterceptors = new ArrayIterator($aroundMethodInterceptors);
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
            /**
             * This will ensure that after all connected output channels finish, we can fetch the result
             * and pass it to origin reply channel
             */
            $bridge = QueueChannel::create('request-reply-' . Uuid::uuid4());
            $message = MessageBuilder::fromMessage($this->requestMessage)
                ->setReplyChannel($bridge)
                ->build();

            $this->requestReplyProducer->executeEndpointAndSendReply($message);

            return $bridge->receive();
        }

        return $aroundMethodInterceptor->invoke(
            $this,
            $this->requestMessage
        );
    }

    /**
     * @return array
     */
    public function getArguments(): array
    {
        return $this->methodCall->getMethodArgumentValues();
    }

    /**
     * @var string|object
     */
    public function getObjectToInvokeOn()
    {
        return $this->messageProcessor->getObjectToInvokeOn();
    }

    /**
     * @return InterfaceToCall
     */
    public function getInterceptedInterface(): InterfaceToCall
    {
        return $this->messageProcessor->getInterceptedInterface();
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
