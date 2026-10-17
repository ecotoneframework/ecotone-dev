<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Projecting\ProjectionTriggerEndpoint;
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
        return $this->trace('Command Bus', $methodInvocation, $message, SpanKind::Server, [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND]);
    }

    public function traceEventBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Event Bus', $methodInvocation, $message, SpanKind::Server, [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND]);
    }

    public function traceQueryBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Query Bus', $methodInvocation, $message, SpanKind::Server, [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND]);
    }

    public function traceCommandHandler(MethodInvocation $methodInvocation, Message $message, CommandHandler $endpoint): mixed
    {
        return $this->trace('Command Handler: ' . $methodInvocation->getName(), $methodInvocation, $message, SpanKind::Server, self::processAttributes(TraceState::destinationOf($message) ?? $endpoint->getEndpointId()));
    }

    public function traceQueryHandler(MethodInvocation $methodInvocation, Message $message, QueryHandler $endpoint): mixed
    {
        return $this->trace('Query Handler: ' . $methodInvocation->getName(), $methodInvocation, $message, SpanKind::Server, self::processAttributes(TraceState::destinationOf($message) ?? $endpoint->getEndpointId()));
    }

    public function traceEventHandler(MethodInvocation $methodInvocation, Message $message, EventHandler $endpoint): mixed
    {
        return $this->trace('Event Handler: ' . $methodInvocation->getName(), $methodInvocation, $message, SpanKind::Server, self::processAttributes(TraceState::destinationOf($message) ?? $endpoint->getEndpointId()));
    }

    public function traceMessageHandler(MethodInvocation $methodInvocation, Message $message, InternalHandler $endpoint): mixed
    {
        return $this->trace('Message Handler: ' . $methodInvocation->getName(), $methodInvocation, $message, SpanKind::Server, self::processAttributes(TraceState::destinationOf($message) ?? $endpoint->getEndpointId()));
    }

    public function traceAsynchronousEndpoint(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $headers = $message->getHeaders();
        $name = match (true) {
            $headers->containsKey(MessageHeaders::POLLED_CHANNEL_NAME) => 'Receiving from channel: ' . $headers->get(MessageHeaders::POLLED_CHANNEL_NAME),
            $headers->containsKey(MessageHeaders::INBOUND_REQUEST_CHANNEL) => 'Receiving from inbound channel adapter: ' . $headers->get(MessageHeaders::INBOUND_REQUEST_CHANNEL),
            default => 'Endpoint: ' . $headers->get(MessageHeaders::CONSUMER_POLLING_METADATA)->getEndpointId() . ' produced Message',
        };

        $attributes = [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_PROCESS];
        if ($headers->containsKey(MessageHeaders::POLLED_CHANNEL_NAME)) {
            $attributes[SpanAttribute::MESSAGING_DESTINATION_NAME] = $headers->get(MessageHeaders::POLLED_CHANNEL_NAME);
        }

        return $this->trace($name, $methodInvocation, $message, SpanKind::Consumer, $attributes);
    }

    public function traceProjection(MethodInvocation $methodInvocation, Message $message, ProjectionTriggerEndpoint $trigger): mixed
    {
        return $this->trace('Projection: ' . $trigger->projectionName, $methodInvocation, $message, SpanKind::Consumer, [
            SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_PROCESS,
            SpanAttribute::PROJECTION_NAME => $trigger->projectionName,
        ]);
    }

    public function traceDistributedBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('Distributed Bus', $methodInvocation, $message, SpanKind::Producer, [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND]);
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

    /**
     * @return array<string, string>
     */
    private static function processAttributes(string $endpointId): array
    {
        $attributes = [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_PROCESS];
        if ($endpointId !== '') {
            $attributes[SpanAttribute::ENDPOINT_ID] = $endpointId;
        }

        return $attributes;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function trace(string $name, MethodInvocation $methodInvocation, Message $message, SpanKind $kind, array $attributes): mixed
    {
        $isLocalRoot = $this->tracer->currentSpan() === null;
        if ($isLocalRoot && $kind !== SpanKind::Consumer) {
            $attributes[SpanAttribute::TRACE_ROOT] = true;
        }
        $span = $this->tracer->startSpan($name, $kind, [...MessageSpanAttributes::from($message), ...$attributes], $isLocalRoot ? self::remoteParentOf($message) : null);

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
