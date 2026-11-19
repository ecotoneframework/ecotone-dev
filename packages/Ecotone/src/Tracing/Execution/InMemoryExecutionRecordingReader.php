<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\ExecutionRecording;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Messaging\Config\ConfigurationException;

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

    public function pop(): ExecutionRecording
    {
        return RecordedExecution::of($this->popSpans())->toRecording();
    }

    public function popSpans(): array
    {
        $keeping = [];
        foreach ($this->spanRecorders as $spanRecorder) {
            if ($spanRecorder instanceof InMemorySpanRecorder) {
                $keeping[spl_object_id($spanRecorder)] = $spanRecorder;
            }
        }
        if ($keeping === []) {
            throw ConfigurationException::create('The execution recording was asked for, but no InMemorySpanRecorder keeps the spans it is read from: the span recorders registered with TracingConfiguration::withSpanRecorder() send spans on without keeping them. Register InMemorySpanRecorder as well, with TracingConfiguration::withSpanRecorder(InMemorySpanRecorder::class) and an InMemorySpanRecorder service; it is meant for tests and keeps every span until the recording is popped.');
        }
        $spans = array_merge(...array_map(fn (InMemorySpanRecorder $spanRecorder): array => $spanRecorder->recordedSpans(), array_values($keeping)));
        foreach ($keeping as $spanRecorder) {
            $spanRecorder->clear();
        }

        return $spans;
    }
}
