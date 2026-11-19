<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanRecorder;

/**
 * licence Apache-2.0
 */
final class StepSpanCapture implements SpanRecorder
{
    /** @var array<string, ?Span> */
    private array $captured = [];

    public function __construct(private SpanRecorder $spanRecorder)
    {
    }

    public function record(Span $span): void
    {
        if (array_key_exists($span->spanId, $this->captured)) {
            $this->captured[$span->spanId] = $span;

            return;
        }
        $this->spanRecorder->record($span);
    }

    public function capture(string $spanId): void
    {
        $this->captured[$spanId] = null;
    }

    public function popCaptured(string $spanId): ?Span
    {
        $span = $this->captured[$spanId] ?? null;
        unset($this->captured[$spanId]);

        return $span;
    }

    public function release(Span $span): void
    {
        $this->spanRecorder->record($span);
    }
}
