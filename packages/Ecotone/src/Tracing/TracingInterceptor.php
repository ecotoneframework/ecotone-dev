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
        return $this->trace('send command', $methodInvocation, $message, SpanKind::Server, [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND]);
    }

    public function traceEventBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('send event', $methodInvocation, $message, SpanKind::Server, [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND]);
    }

    public function traceQueryBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('send query', $methodInvocation, $message, SpanKind::Server, [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND]);
    }

    public function traceCommandHandler(MethodInvocation $methodInvocation, Message $message, CommandHandler $endpoint): mixed
    {
        return $this->traceHandler($methodInvocation, $message, TraceState::destinationOf($message) ?? $endpoint->getEndpointId());
    }

    public function traceQueryHandler(MethodInvocation $methodInvocation, Message $message, QueryHandler $endpoint): mixed
    {
        return $this->traceHandler($methodInvocation, $message, TraceState::destinationOf($message) ?? $endpoint->getEndpointId());
    }

    public function traceEventHandler(MethodInvocation $methodInvocation, Message $message, EventHandler $endpoint): mixed
    {
        return $this->traceHandler($methodInvocation, $message, TraceState::destinationOf($message) ?? $endpoint->getEndpointId());
    }

    public function traceMessageHandler(MethodInvocation $methodInvocation, Message $message, InternalHandler $endpoint): mixed
    {
        return $this->traceHandler($methodInvocation, $message, TraceState::destinationOf($message) ?? $endpoint->getEndpointId());
    }

    public function traceAsynchronousEndpoint(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $headers = $message->getHeaders();
        $name = match (true) {
            $headers->containsKey(MessageHeaders::POLLED_CHANNEL_NAME) => 'receive ' . $headers->get(MessageHeaders::POLLED_CHANNEL_NAME),
            $headers->containsKey(MessageHeaders::INBOUND_REQUEST_CHANNEL) => 'receive ' . $headers->get(MessageHeaders::INBOUND_REQUEST_CHANNEL),
            default => 'poll ' . $headers->get(MessageHeaders::CONSUMER_POLLING_METADATA)->getEndpointId(),
        };

        $attributes = [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_PROCESS];
        if ($headers->containsKey(MessageHeaders::POLLED_CHANNEL_NAME)) {
            $attributes[SpanAttribute::MESSAGING_DESTINATION_NAME] = $headers->get(MessageHeaders::POLLED_CHANNEL_NAME);
        }

        return $this->trace($name, $methodInvocation, $message, SpanKind::Consumer, $attributes);
    }

    public function traceProjection(MethodInvocation $methodInvocation, Message $message, ProjectionTriggerEndpoint $trigger): mixed
    {
        return $this->trace('process ' . $trigger->projectionName, $methodInvocation, $message, SpanKind::Consumer, [
            SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_PROCESS,
            SpanAttribute::PROJECTION_NAME => $trigger->projectionName,
        ]);
    }

    public function traceDistributedBus(MethodInvocation $methodInvocation, Message $message): mixed
    {
        return $this->trace('send distributed', $methodInvocation, $message, SpanKind::Producer, [SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND]);
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

    private function traceHandler(MethodInvocation $methodInvocation, Message $message, string $endpointId): mixed
    {
        $attributes = [
            SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_PROCESS,
            SpanAttribute::CODE_FUNCTION_NAME => $methodInvocation->getName(),
        ];
        if ($endpointId !== '') {
            $attributes[SpanAttribute::ENDPOINT_ID] = $endpointId;
        }

        return $this->trace('process ' . self::shortNameOf($methodInvocation->getName()), $methodInvocation, $message, SpanKind::Server, $attributes);
    }

    private static function shortNameOf(string $classAndMethod): string
    {
        [$class, $method] = explode('::', $classAndMethod, 2);
        $class = explode("\0", $class, 2)[0];

        return substr($class, (int) strrpos('\\' . $class, '\\')) . '::' . $method;
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
