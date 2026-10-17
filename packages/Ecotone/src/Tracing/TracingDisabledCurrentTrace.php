<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Tracing\CurrentTrace;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class TracingDisabledCurrentTrace implements CurrentTrace
{
    public function traceId(): ?string
    {
        throw ConfigurationException::create('No trace is recorded, so there is no current trace id. Enable tracing: register TracingConfiguration::createWithDefaults()->withSpanRecorder() or ->withStoredTraces() from a #[ServiceContext] method, or install ecotone/open-telemetry with a TracerProviderInterface.');
    }
}
