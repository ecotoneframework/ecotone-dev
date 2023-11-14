<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProviderInterface;

final class EcotoneForcedTraceFlush
{
    /**
     * @param SdkTracerProviderInterface $tracerProvider
     */
    public function __construct(private TracerProviderInterface $tracerProvider)
    {

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
