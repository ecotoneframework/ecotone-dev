<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Ecotone\Messaging\Message;

final class TracingEndpointAndGatewayInterceptor
{
    public function trace(MethodInvocation $methodInvocation, Message $message, ReferenceSearchService $referenceSearchService)
    {
        /** @var TracerInterface $tracer */
        $tracer = $referenceSearchService->get(TracerInterface::class);

        try {
            $span = $tracer
                        ->spanBuilder("endpoint: " . $methodInvocation->getInterceptedClassName() . "::" . $methodInvocation->getInterceptedMethodName())
                        ->setSpanKind(SpanKind::KIND_INTERNAL)
                        ->setAttribute("message_id", $message->getHeaders()->getMessageId())
                        ->setAttribute("message_correlation_id", 'some')
                        ->startSpan();
            $spanScope = $span->activate();

            $result = $methodInvocation->proceed();
        } catch (\Throwable $exception) {
            //The library's code shouldn't be throwing unhandled exceptions (it should emit any errors via diagnostic events)
            //This is intended to illustrate a way you can capture unhandled exceptions coming from your app code
            $span->recordException($exception);
            $this->closeSpan($span, $spanScope);

            throw $exception;
        }

        $this->closeSpan($span, $spanScope);

        return $result;
    }

    private function closeSpan(\OpenTelemetry\API\Trace\SpanInterface $span, \OpenTelemetry\Context\ScopeInterface $spanScope): void
    {
        $spanScope->detach();
        $span->end();
    }
}