<?php

declare(strict_types=1);

namespace Ecotone\Modelling\AggregateFlow\CallAggregate;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\CallAggregateService;

/**
 * licence Apache-2.0
 */
final class CallStateBasedAggregateService implements CallAggregateService
{
    /**
     * @param array<ParameterConverter> $parameterConverters
     * @param array<AroundMethodInterceptor> $aroundMethodInterceptors
     */
    public function __construct(
        private AggregateMethodInvoker $aggregateMethodInvoker,
        private ?Type $returnType,
        private bool $isCommandHandler,
    ) {
    }

    public function call(Message $message): ?Message
    {
        $resultMessage = MessageBuilder::fromMessage($message);

        $calledAggregate = $message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_OBJECT) ? $message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_OBJECT) : null;

        $result = $this->aggregateMethodInvoker->execute($message);

        $resultType = TypeDescriptor::createFromVariable($result);
        if ($resultType->isIterable() && $this->returnType?->isCollection()) {
            $resultType = $this->returnType;
        }

        if (! is_null($result)) {
            if ($this->isCommandHandler) {
                $resultMessage = $resultMessage->setHeader(AggregateMessage::CALLED_AGGREGATE_OBJECT, $calledAggregate);
            }

            $resultMessage = $resultMessage
                ->setContentType(MediaType::createApplicationXPHPWithTypeParameter($resultType->toString()))
                ->setPayload($result)
            ;
        }

        if ($this->isCommandHandler && is_null($result)) {
            $resultMessage = $resultMessage->setHeader(AggregateMessage::NULL_EXECUTION_RESULT, true);
        }

        if ($this->isCommandHandler || ! is_null($result)) {
            return $resultMessage->build();
        }

        return null;
    }
}
