<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\ExecutionRecording;
use Ecotone\Api\Tracing\InMemorySpanRecorder;

/**
 * licence Apache-2.0
 */
final class InMemoryExecutionRecordingReader implements ExecutionRecordingReader
{
    public function __construct(private InMemorySpanRecorder $spanRecorder)
    {
    }

    public function pop(): ExecutionRecording
    {
        $spans = $this->spanRecorder->recordedSpans();
        $this->spanRecorder->clear();

        return RecordedExecution::of($spans)->toRecording();
    }
}
