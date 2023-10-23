<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;

use function json_decode;

use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class TracerInterceptor
{
    public function __construct(private TracerInterface $tracer, private LoggerInterface $logger)
    {
    }

    public function traceAsynchronousEndpoint(MethodInvocation $methodInvocation, Message $message)
    {
        /**
         * @TODO tag polledChannelName, routingSlip
         */
        /** @TODO provides same parent to all child spans */
        $carrier = $message->getHeaders()->containsKey(TracingChannelInterceptor::TRACING_CARRIER_HEADER) ? json_decode($message->getHeaders()->get(TracingChannelInterceptor::TRACING_CARRIER_HEADER), true) : [];
        $parentContext = TraceContextPropagator::getInstance()->extract($carrier);

        return $this->trace(
            'Receiving from channel: ' . $message->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME),
            $methodInvocation,
            $message,
            parentContext: $parentContext,
            spanKind: SpanKind::KIND_CONSUMER
        );
    }

    public function traceCommandHandler(MethodInvocation $methodInvocation, Message $message)
    {
        return $this->trace(
            'Command Handler: ' . $methodInvocation->getInterfaceToCall()->toString(),
            $methodInvocation,
            $message,
        );
    }

    public function traceQueryHandler(MethodInvocation $methodInvocation, Message $message)
    {
        return $this->trace(
            'Query Handler: ' . $methodInvocation->getInterfaceToCall()->toString(),
            $methodInvocation,
            $message,
        );
    }

    public function traceEventHandler(MethodInvocation $methodInvocation, Message $message)
    {
        return $this->trace(
            'Event Handler: ' . $methodInvocation->getInterfaceToCall()->toString(),
            $methodInvocation,
            $message,
        );
    }

    public function traceCommandBus(MethodInvocation $methodInvocation, Message $message)
    {
        return $this->trace(
            'Command Bus',
            $methodInvocation,
            $message,
        );
    }

    public function traceEventBus(MethodInvocation $methodInvocation, Message $message)
    {
        return $this->trace(
            'Event Bus',
            $methodInvocation,
            $message,
        );
    }

    public function traceQueryBus(MethodInvocation $methodInvocation, Message $message)
    {
        return $this->trace(
            'Query Bus',
            $methodInvocation,
            $message,
        );
    }

    public function trace(
        string $type,
        MethodInvocation $methodInvocation,
        Message $message,
        array $attributes = [],
        ?ContextInterface $parentContext = null,
        int $spanKind = SpanKind::KIND_SERVER
    ) {
        /** @TODO this should be moved somewhere else */
        if (! LoggerHolder::isSet()) {
            LoggerHolder::set($this->logger);
        }

        $span = EcotoneSpanBuilder::create(
            $message,
            $type,
            $this->tracer
        )
            ->setParent($parentContext)
            ->startSpan();
        $spanScope = $span->activate();

        try {
            $result = $methodInvocation->proceed();
        } catch (Throwable $exception) {
            $span->recordException($exception);
            $this->closeSpan($span, $spanScope, StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        }

        $this->closeSpan($span, $spanScope, StatusCode::STATUS_OK, null);

        return $result;
    }

    private function closeSpan(SpanInterface $span, ScopeInterface $spanScope, string $statusCode, ?string $descriptionStatusCode): void
    {
        $span->setStatus($statusCode, $descriptionStatusCode);
        $spanScope->detach();
        $span->end();
    }
}
