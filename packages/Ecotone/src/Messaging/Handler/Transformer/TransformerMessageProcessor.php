<?php

namespace Ecotone\Messaging\Handler\Transformer;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MessageConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodCallProvider;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\StaticMethodCallProvider;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * Class TransformerMessageProcessor
 * @package Messaging\Handler\Transformer
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 * @internal
 */
/**
 * licence Apache-2.0
 */
class TransformerMessageProcessor implements RealMessageProcessor
{
    public function __construct(
        private MethodCallProvider $methodCallProvider,
        private Type $returnType)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(Message $message): ?Message
    {
        $reply = $this->methodCallProvider->getMethodInvocation($message)->proceed();
        return match (true) {
            is_null($reply) => null,
            $reply instanceof Message => $reply,
            is_array($reply) => MessageBuilder::fromMessage($message)
                ->setMultipleHeaders($reply)
                ->build(),
            default => MessageBuilder::fromMessage($message)
                ->setPayload($reply)
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter($this->returnType->toString()))
                ->build()
        };
    }

    public function toMethodCallProvider(): MethodCallProvider
    {
        return new StaticMethodCallProvider(
            $this,
            "executeEndpoint",
            [new MessageConverter()],
            ["message"],
        );
    }
}
