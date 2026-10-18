<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class InMemoryTraceStore implements TraceStore
{
    /** @var array<string, array<string, Span>> */
    private array $spansByTrace = [];
    /** @var array<string, DatePoint> */
    private array $completedAt = [];

    public function append(array $spans): void
    {
        foreach ($spans as $span) {
            if (isset($this->spansByTrace[$span->traceId][$span->spanId])) {
                continue;
            }
            $this->spansByTrace[$span->traceId][$span->spanId] = $span;
            unset($this->completedAt[$span->traceId]);
        }
    }

    public function markCompletedIfDone(string $traceId, DatePoint $now): bool
    {
        if (isset($this->completedAt[$traceId]) || ! $this->progressOf($traceId)->isCompleted()) {
            return false;
        }
        $this->completedAt[$traceId] = $now;

        return true;
    }

    public function progressOf(string $traceId): TraceProgress
    {
        return TraceLedger::progressOf($traceId, array_values($this->spansByTrace[$traceId] ?? []));
    }

    public function removeExpiredTraces(DatePoint $completedTracesStartedBefore, DatePoint $anyTraceStartedBefore): void
    {
        foreach ($this->spansByTrace as $traceId => $spans) {
            $startedAt = min(array_map(fn (Span $span): DatePoint => $span->startedAt, $spans));
            if ($startedAt < $anyTraceStartedBefore || (isset($this->completedAt[$traceId]) && $startedAt < $completedTracesStartedBefore)) {
                unset($this->spansByTrace[$traceId], $this->completedAt[$traceId]);
            }
        }
    }

    public function withinTransaction(Closure $work): mixed
    {
        return $work();
    }
}
