<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Messaging\Config\ConfigurationException;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProviderInterface;

/**
 * licence Apache-2.0
 */
final class EcotoneForcedTraceFlush
{
    private function __construct(private SdkTracerProviderInterface $tracerProvider)
    {
    }

    public static function create(TracerProviderInterface $tracerProvider): self
    {
        if (! $tracerProvider instanceof SdkTracerProviderInterface) {
            throw ConfigurationException::create(sprintf(
                'Tracing forces a flush of collected spans after every bus call and every handled asynchronous message, but %s is bound to %s, which cannot flush: only an implementation of %s can. '
                . 'Bind %s to an SDK tracer provider, for example new OpenTelemetry\SDK\Trace\TracerProvider(new OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor($exporter, OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault())), '
                . 'or turn the forced flush off in a #[ServiceContext] with TracingConfiguration::createWithDefaults()->withForceFlushOnBusExecution(false)->withForceFlushOnAsynchronousMessageHandled(false).',
                TracerProviderInterface::class,
                $tracerProvider::class,
                SdkTracerProviderInterface::class,
                TracerProviderInterface::class,
            ));
        }

        return new self($tracerProvider);
    }

    public function flush(MethodInvocation $methodInvocation): mixed
    {
        try {
            $result = $methodInvocation->proceed();
        } finally {
            $this->tracerProvider->forceFlush();
        }

        return $result;
    }
}
