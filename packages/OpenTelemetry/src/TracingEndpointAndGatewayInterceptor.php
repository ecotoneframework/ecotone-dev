<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Message;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class TracingEndpointAndGatewayInterceptor
{
    public function trace(MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        /** @var TracerInterface $tracer */
        $tracer = $referenceSearchService->get(TracerInterface::class);

        try {
            $span = EcotoneSpanBuilder::create(
                $message,
                'endpoint: ' . $methodInvocation->getInterceptedClassName() . '::' . $methodInvocation->getInterceptedMethodName(),
                $tracer
            )->startSpan();
            $spanScope = $span->activate();

            $result = $methodInvocation->proceed();
        } catch (Throwable $exception) {
            //The library's code shouldn't be throwing unhandled exceptions (it should emit any errors via diagnostic events)
            //This is intended to illustrate a way you can capture unhandled exceptions coming from your app code
            $span->recordException($exception);
            $this->closeSpan($span, $spanScope);

            throw $exception;
        }

        $this->closeSpan($span, $spanScope);

        return $result;
    }

    private function closeSpan(SpanInterface $span, ScopeInterface $spanScope): void
    {
        $spanScope->detach();
        $span->end();
    }
}