<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\ExecutionRecording;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class ExecutionRecordingNotSwitchedOn implements ExecutionRecordingReader
{
    public function read(): ExecutionRecording
    {
        throw ConfigurationException::create('The execution recording was asked for, but this application does not record its execution, so there is nothing to read. Switch it on with TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withExecutionRecording() in a #[ServiceContext] or in the extension objects of EcotoneLite::bootstrapFlowTesting().');
    }
}
