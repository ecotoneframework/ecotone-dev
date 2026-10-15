<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Api\Interceptor\MethodInvocation;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProviderInterface;

/**
 * licence Apache-2.0
 */
final class EcotoneForcedTraceFlush
{
    public function __construct(private TracerProviderInterface $tracerProvider)
    {
    }

    public function flush(MethodInvocation $methodInvocation): mixed
    {
        try {
            $result = $methodInvocation->proceed();
        } finally {
            if ($this->tracerProvider instanceof SdkTracerProviderInterface) {
                $this->tracerProvider->forceFlush();
            }
        }

        return $result;
    }
}
