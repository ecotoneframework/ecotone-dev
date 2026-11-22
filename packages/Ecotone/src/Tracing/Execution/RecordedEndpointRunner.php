<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\EndpointRunner;

/**
 * licence Apache-2.0
 */
final class RecordedEndpointRunner implements EndpointRunner
{
    public function __construct(private EndpointRunner $endpointRunner, private ExecutionRecordingTracer $tracer)
    {
    }

    public function runEndpointWithExecutionPollingMetadata(?ExecutionPollingMetadata $executionPollingMetadata = null): void
    {
        $this->tracer->suspendOpenEntry();
        try {
            $this->endpointRunner->runEndpointWithExecutionPollingMetadata($executionPollingMetadata);
        } finally {
            $this->tracer->resumeSuspendedEntry();
        }
    }
}
