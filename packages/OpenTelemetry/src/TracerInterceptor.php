<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Logger\LoggingService;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Message;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Common\Log\LoggerHolder;
use Psr\Log\LoggerInterface;
use Throwable;

final class TracerInterceptor
{
    public function traceCommandHandler(MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        return $this->trace(
            'Command Handler: ' . $methodInvocation->getInterceptedClassName() . '::' . $methodInvocation->getInterceptedMethodName(),
            $methodInvocation,
            $message,
            $referenceSearchService
        );
    }

    public function traceQueryHandler(MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        return $this->trace(
            'Query Handler: ' . $methodInvocation->getInterceptedClassName() . '::' . $methodInvocation->getInterceptedMethodName(),
            $methodInvocation,
            $message,
            $referenceSearchService
        );
    }

    public function traceEventHandler(MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        return $this->trace(
             'Event Handler: ' . $methodInvocation->getInterceptedClassName() . '::' . $methodInvocation->getInterceptedMethodName(),
            $methodInvocation,
            $message,
            $referenceSearchService
        );
    }

    public function traceCommandBus(MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        return $this->trace(
            'Command Bus',
            $methodInvocation,
            $message,
            $referenceSearchService
        );
    }

    public function traceEventBus(MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        return $this->trace(
            'Event Bus',
            $methodInvocation,
            $message,
            $referenceSearchService
        );
    }

    public function traceQueryBus(MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        return $this->trace(
            'Event Bus',
            $methodInvocation,
            $message,
            $referenceSearchService
        );
    }

    public function trace(string $type, MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        if (!LoggerHolder::isSet()) {
            /** @var LoggerInterface $logger */
            $logger = $referenceSearchService->get(LoggingHandlerBuilder::LOGGER_REFERENCE);
            LoggerHolder::set($logger);
        }

        /** @var TracerInterface $tracer */
        $tracer = $referenceSearchService->get(TracerInterface::class);

        try {
            $span = EcotoneSpanBuilder::create(
                $message,
                $type,
                $tracer
            )->startSpan();
            $spanScope = $span->activate();

            $result = $methodInvocation->proceed();
        } catch (Throwable $exception) {
            //The library's code shouldn't be throwing unhandled exceptions (it should emit any errors via diagnostic events)
            //This is intended to illustrate a way you can capture unhandled exceptions coming from your app code
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