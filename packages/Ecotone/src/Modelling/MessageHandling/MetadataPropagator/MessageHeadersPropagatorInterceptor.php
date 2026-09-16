<?php

namespace Ecotone\Modelling\MessageHandling\MetadataPropagator;

use Closure;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\PropagateHeaders;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\AggregateNotFoundException;
use WeakMap;

/**
 * licence Apache-2.0
 */
class MessageHeadersPropagatorInterceptor
{
    public const GET_CURRENTLY_PROPAGATED_HEADERS_CHANNEL = 'ecotone.getCurrentlyPropagatedHeaders';
    public const ENABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT = 'ecotone.enablePollingConsumerPropagation';
    public const DISABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT = 'ecotone.disablePollingConsumerPropagation';
    public const IS_POLLING_CONSUMER_PROPAGATION_CONTEXT = 'ecotone.isPollingConsumerPropagation';
    private array $currentlyPropagatedHeaders = [];
    /** @var MessageCausation[] */
    private array $causationChain = [];
    private WeakMap $exceptionsWithCausationChain;
    private bool $isPollingConsumer = false;

    public function __construct()
    {
        $this->exceptionsWithCausationChain = new WeakMap();
    }

    public function storeHeaders(MethodInvocation|Closure $methodInvocation, Message $message, ?PropagateHeaders $propagateHeaders = null)
    {
        if ($propagateHeaders !== null && ! $propagateHeaders->doPropagation()) {
            $userlandHeaders = [];
        } else {
            $userlandHeaders = MessageHeaders::unsetAllFrameworkHeaders($message->getHeaders()->headers());
            unset(
                $userlandHeaders[AggregateMessage::OVERRIDE_AGGREGATE_IDENTIFIER],
                $userlandHeaders[AggregateMessage::AGGREGATE_ID],
                $userlandHeaders[AggregateMessage::CALLED_AGGREGATE_CLASS],
                $userlandHeaders[AggregateMessage::CALLED_AGGREGATE_INSTANCE],
                $userlandHeaders[AggregateMessage::TARGET_VERSION],
            );
            $userlandHeaders[MessageHeaders::MESSAGE_ID] = $message->getHeaders()->getMessageId();
            $userlandHeaders[MessageHeaders::MESSAGE_CORRELATION_ID] = $message->getHeaders()->getCorrelationId();
        }

        $this->currentlyPropagatedHeaders[] = $userlandHeaders;
        $this->causationChain[] = MessageCausation::from($methodInvocation, $message);
        try {
            if ($methodInvocation instanceof MethodInvocation) {
                $reply = $methodInvocation->proceed();
            } else {
                $reply = $methodInvocation();
            }
        } catch (AggregateNotFoundException $exception) {
            throw $this->withCausationChain($exception);
        } finally {
            array_pop($this->currentlyPropagatedHeaders);
            array_pop($this->causationChain);
        }

        return $reply;
    }

    private function withCausationChain(AggregateNotFoundException $exception): AggregateNotFoundException
    {
        if (count($this->causationChain) < 2 || isset($this->exceptionsWithCausationChain[$exception])) {
            return $exception;
        }

        $exceptionWithCausationChain = AggregateNotFoundException::createFromPreviousException(
            $exception->getMessage() . '. ' . MessageCausation::describeChain($this->causationChain, $exception),
            $exception
        );
        $this->exceptionsWithCausationChain[$exceptionWithCausationChain] = true;

        return $exceptionWithCausationChain;
    }

    public function propagateHeaders(array $headers): array
    {
        if (array_key_exists(MessageHeaders::STREAM_BASED_SOURCED, $headers) && $headers[MessageHeaders::STREAM_BASED_SOURCED]) {
            return $headers;
        }

        return MessageHeaders::propagateContextHeaders($this->getLastHeaders(), $headers);
    }

    /**
     * @return array<string, mixed>
     */
    #[InternalHandler(self::GET_CURRENTLY_PROPAGATED_HEADERS_CHANNEL)]
    public function getLastHeaders(): array
    {
        $headers = end($this->currentlyPropagatedHeaders);

        if ($this->isCalledForFirstTime($headers)) {
            return [];
        }

        return $headers;
    }

    #[InternalHandler(self::ENABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT)]
    public function enablePollingConsumerPropagation(): void
    {
        $this->isPollingConsumer = true;
    }

    #[InternalHandler(self::DISABLE_POLLING_CONSUMER_PROPAGATION_CONTEXT)]
    public function disablePollingConsumerPropagation(): void
    {
        $this->isPollingConsumer = false;
    }

    #[InternalHandler(self::IS_POLLING_CONSUMER_PROPAGATION_CONTEXT)]
    public function isPollingConsumer(): bool
    {
        return $this->isPollingConsumer;
    }

    private function isCalledForFirstTime($headers): bool
    {
        return $headers === false;
    }
}
