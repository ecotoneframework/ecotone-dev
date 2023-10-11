<?php

namespace Ecotone\Messaging\Endpoint;

interface EndpointRunner
{
    public function runEndpointWithExecutionPollingMetadata(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata): void;
}