<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Messaging\Support\MessageBuilder;
use Throwable;

/**
 * licence Apache-2.0
 */
final class TracingInterceptor
{
    public function __construct(
        private Tracer $tracer,
        private MessageBuilder $messageBuilder,
    ) {
    }

    public function traceCommandBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Command Bus', $methodInvocation, $message);
    }

    public function traceEventBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Event Bus', $methodInvocation, $message);
    }

    public function traceQueryBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Query Bus', $methodInvocation, $message);
    }

    public function traceCommandHandler(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Command Handler: ' . $methodInvocation->getName(), $methodInvocation, $message);
    }

    public function traceQueryHandler(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Query Handler: ' . $methodInvocation->getName(), $methodInvocation, $message);
    }

    public function traceEventHandler(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Event Handler: ' . $methodInvocation->getName(), $methodInvocation, $message);
    }

    public function traceMessageHandler(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Message Handler: ' . $methodInvocation->getName(), $methodInvocation, $message);
    }

    public function traceAsynchronousEndpoint(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $headers = $message->getHeaders();
        $name = match (true) {
            $headers->containsKey(MessageHeaders::POLLED_CHANNEL_NAME) => 'Receiving from channel: ' . $headers->get(MessageHeaders::POLLED_CHANNEL_NAME),
            $headers->containsKey(MessageHeaders::INBOUND_REQUEST_CHANNEL) => 'Receiving from inbound channel adapter: ' . $headers->get(MessageHeaders::INBOUND_REQUEST_CHANNEL),
            default => 'Endpoint: ' . $headers->get(MessageHeaders::CONSUMER_POLLING_METADATA)->getEndpointId() . ' produced Message',
        };

        return $this->trace($name, $methodInvocation, $message, SpanKind::Consumer, self::remoteParentOf($message));
    }

    public function traceDistributedBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Distributed Bus', $methodInvocation, $message, SpanKind::Producer);
    }

    public function provideContextForDistributedBus(Message $message): Message
    {
        $currentSpan = $this->tracer->currentSpan();
        if ($currentSpan === null) {
            return $message;
        }

        return $this->messageBuilder->fromMessage($message)
            ->setHeader(MessageHeaders::TRACEPARENT, $currentSpan->context()->toTraceparent())
            ->build();
    }

    public static function remoteParentOf(Message $message): ?SpanContext
    {
        $headers = $message->getHeaders();

        return SpanContext::fromTraceparent($headers->containsKey(MessageHeaders::TRACEPARENT) ? $headers->get(MessageHeaders::TRACEPARENT) : null);
    }

    private function trace(string $name, MethodInvocation $methodInvocation, Message $message, SpanKind $kind = SpanKind::Server, ?SpanContext $remoteParent = null): mixed
    {
        $span = $this->tracer->startSpan($name, $kind, MessageSpanAttributes::from($message), $remoteParent);

        try {
            $result = $methodInvocation->proceed();
        } catch (Throwable $failure) {
            $span->fail($failure);

            throw $failure;
        }

        $span->end();

        return $result;
    }
}
