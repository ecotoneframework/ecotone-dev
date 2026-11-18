<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\ExecutionRecording;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\Flow\Snapshot\FlowExecution;

/**
 * licence Apache-2.0
 */
final class InMemoryExecutionRecordingReader implements ExecutionRecordingReader
{
    /**
     * @param list<SpanRecorder> $spanRecorders
     */
    public function __construct(private array $spanRecorders)
    {
    }

    public function read(): ExecutionRecording
    {
        $keeping = [];
        foreach ($this->spanRecorders as $spanRecorder) {
            if ($spanRecorder instanceof InMemorySpanRecorder) {
                $keeping[spl_object_id($spanRecorder)] = $spanRecorder;
            }
        }
        if ($keeping === []) {
            throw ConfigurationException::create('The execution recording was asked for, but no InMemorySpanRecorder keeps the spans it is read from: the span recorders registered with TracingConfiguration::withSpanRecorder() send spans on without keeping them. Register InMemorySpanRecorder as well, with TracingConfiguration::withSpanRecorder(InMemorySpanRecorder::class) and an InMemorySpanRecorder service.');
        }

        return FlowExecution::of(array_merge(...array_map(fn (InMemorySpanRecorder $spanRecorder): array => $spanRecorder->recordedSpans(), array_values($keeping))))->toRecording();
    }
}
