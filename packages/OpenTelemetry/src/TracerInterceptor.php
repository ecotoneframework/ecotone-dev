<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Handler\Logger\LoggingService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;

use Exception;

use function json_decode;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\Span;
use Throwable;

final class TracerInterceptor
{
    public function __construct(private TracerProviderInterface $tracerProvider)
    {
    }

    public function traceAsynchronousEndpoint(MethodInvocation $methodInvocation, Message $message)
    {
        /**
         * @TODO tag polledChannelName, routingSlip
         */
        $carrier = $message->getHeaders()->containsKey(TracingChannelInterceptor::TRACING_CARRIER_HEADER) ? json_decode($message->getHeaders()->get(TracingChannelInterceptor::TRACING_CARRIER_HEADER), true) : [];
        $parentContext = TraceContextPropagator::getInstance()->extract($carrier);

        $scope = $parentContext->activate();
        try {
            $trace = $this->trace(
                'Receiving from channel: ' . $message->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME),
                $methodInvocation,
                $message,
                spanKind: SpanKind::KIND_CONSUMER,
            );
        } finally {
            $scope->detach();
        }

        return $trace;
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

    public function traceLogs(MethodInvocation $methodInvocation, Message $message)
    {
        $logMessage = $message->getPayload();
        /** @var Message $contextMessage */
        $contextMessage = $message->getHeaders()->get(LoggingService::CONTEXT_MESSAGE_HEADER);
        $attributes = [
            MessageHeaders::MESSAGE_ID => $contextMessage->getHeaders()->getMessageId(),
            MessageHeaders::MESSAGE_CORRELATION_ID => $contextMessage->getHeaders()->getCorrelationId(),
            MessageHeaders::PARENT_MESSAGE_ID => $contextMessage->getHeaders()->getParentId(),
        ];

        if ($message->getHeaders()->containsKey(LoggingService::CONTEXT_EXCEPTION_HEADER)) {
            /** @var Exception $exception */
            $exception = $message->getHeaders()->get(LoggingService::CONTEXT_EXCEPTION_HEADER);
            $attributes['exceptionMessage'] = $exception->getMessage();
            $attributes['exceptionClass'] = $exception::class;
            $attributes['exceptionTrace'] = $exception->getTraceAsString();
        }

        Span::getCurrent()->addEvent(
            $logMessage,
            $attributes
        );

        return $methodInvocation->proceed();
    }

    public function trace(
        string $type,
        MethodInvocation $methodInvocation,
        Message $message,
        array $attributes = [],
        int $spanKind = SpanKind::KIND_SERVER,
    ) {
        $span = EcotoneSpanBuilder::create(
            $message,
            $type,
            $this->tracerProvider,
            $spanKind
        )
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
        $span->end();
        $spanScope->detach();
    }
}
