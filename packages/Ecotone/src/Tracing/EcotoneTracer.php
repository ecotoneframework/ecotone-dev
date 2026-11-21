<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use function array_search;
use function array_splice;
use function count;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Messaging\Execution\ExecutionList;
use Ecotone\Messaging\Execution\ExecutionScope;

/**
 * licence Apache-2.0
 */
final class EcotoneTracer implements Tracer
{
    public function __construct(
        private EcotoneClockInterface $clock,
        private SpanRecorder $spanRecorder,
    ) {
    }

    public function startSpan(string $name, SpanKind $kind, array $attributes = [], ?SpanContext $remoteParent = null): ActiveSpan
    {
        $this->currentSpan()?->childStarted();

        return $this->start($name, $kind, $attributes, $remoteParent ?? $this->currentSpan()?->context());
    }

    public function startRootSpan(string $name, SpanKind $kind, array $attributes = []): ActiveSpan
    {
        return $this->start($name, $kind, $attributes, null);
    }

    public function startDetachedSpan(string $name, SpanKind $kind, array $attributes, ?SpanContext $parent, DatePoint $startedAt): ActiveSpan
    {
        return $this->spanOf(name: $name, kind: $kind, attributes: $attributes, parent: $parent, startedAt: $startedAt);
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function start(string $name, SpanKind $kind, array $attributes, ?SpanContext $parent): ActiveSpan
    {
        $span = $this->spanOf(name: $name, kind: $kind, attributes: $attributes, parent: $parent, startedAt: $this->clock->now());
        $this->activeSpans()->items[] = $span;

        return $span;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function spanOf(string $name, SpanKind $kind, array $attributes, ?SpanContext $parent, DatePoint $startedAt): EcotoneActiveSpan
    {
        return new EcotoneActiveSpan(
            $parent?->newChild() ?? SpanContext::newTrace(),
            $parent?->spanId,
            $name,
            $kind,
            $attributes,
            $startedAt,
            $this->clock,
            $this,
        );
    }

    public function currentSpan(): ?ActiveSpan
    {
        return $this->activeSpans()->items[count($this->activeSpans()->items) - 1] ?? null;
    }

    public function currentApplicationSpan(): ?ActiveSpan
    {
        return $this->currentSpan();
    }

    public function endSpan(EcotoneActiveSpan $activeSpan, Span $span): void
    {
        $position = array_search($activeSpan, $this->activeSpans()->items, true);
        if ($position !== false) {
            array_splice($this->activeSpans()->items, $position, 1);
        }

        $this->spanRecorder->record($span);
    }

    private function activeSpans(): ExecutionList
    {
        return ExecutionScope::stateOf($this, static fn () => new ExecutionList());
    }
}
