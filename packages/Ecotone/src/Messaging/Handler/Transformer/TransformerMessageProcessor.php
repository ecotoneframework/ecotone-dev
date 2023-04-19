<?php

namespace Ecotone\Messaging\Handler\Transformer;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * Class TransformerMessageProcessor
 * @package Messaging\Handler\Transformer
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 * @internal
 */
class TransformerMessageProcessor implements MessageProcessor
{
    private \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker $methodInvoker;

    /**
     * TransformerMessageProcessor constructor.
     * @param MethodInvoker $methodInvoker
     */
    private function __construct(MethodInvoker $methodInvoker)
    {
        $this->methodInvoker = $methodInvoker;
    }

    /**
     * @param MethodInvoker $methodInvoker
     * @return TransformerMessageProcessor
     */
    public static function createFrom(MethodInvoker $methodInvoker): self
    {
        return new self($methodInvoker);
    }

    /**
     * @inheritDoc
     */
    public function executeEndpoint(Message $message): ?Message
    {
        $reply = $this->methodInvoker->executeEndpoint($message);
        $replyBuilder = MessageBuilder::fromMessage($message);

        if (is_null($reply)) {
            return null;
        }

        if (is_array($reply)) {
            $reply = $replyBuilder
                ->setMultipleHeaders($reply)
                ->build();
        } elseif (! ($reply instanceof Message)) {
            $reply = $replyBuilder
                ->setPayload($reply)
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter($this->methodInvoker->getInterfaceToCall()->getReturnType()->toString()))
                ->build();
        }

        return $reply;
    }

    public function getMethodCall(Message $message): MethodCall
    {
        return $this->methodInvoker->getMethodCall($message);
    }

    public function getAroundMethodInterceptors(): array
    {
        return $this->methodInvoker->getAroundMethodInterceptors();
    }

    public function getObjectToInvokeOn(): string|object
    {
        return $this->methodInvoker->getObjectToInvokeOn();
    }

    public function getInterceptedInterface(): InterfaceToCall
    {
        return $this->methodInvoker->getInterceptedInterface();
    }

    public function getEndpointAnnotations(): array
    {
        return $this->methodInvoker->getEndpointAnnotations();
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string)$this->methodInvoker;
    }
}
