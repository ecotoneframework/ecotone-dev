<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Messaging\Execution\ExecutionList;
use Ecotone\Messaging\Execution\ExecutionScope;

/**
 * licence Apache-2.0
 */
final class StepSpanCapture implements SpanRecorder
{
    public function __construct(private SpanRecorder $spanRecorder)
    {
    }

    public function record(Span $span): void
    {
        if (array_key_exists($span->spanId, $this->captured()->items)) {
            $this->captured()->items[$span->spanId] = $span;

            return;
        }
        $this->spanRecorder->record($span);
    }

    public function capture(string $spanId): void
    {
        $this->captured()->items[$spanId] = null;
    }

    public function popCaptured(string $spanId): ?Span
    {
        $span = $this->captured()->items[$spanId] ?? null;
        unset($this->captured()->items[$spanId]);

        return $span;
    }

    public function release(Span $span): void
    {
        $this->spanRecorder->record($span);
    }

    private function captured(): ExecutionList
    {
        return ExecutionScope::stateOf($this, static fn () => new ExecutionList());
    }
}
