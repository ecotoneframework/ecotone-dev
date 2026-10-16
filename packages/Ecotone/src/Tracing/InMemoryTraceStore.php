<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

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
    /** @var array<string, Span[]> */
    private array $spansByTrace = [];
    /** @var array<string, true> */
    private array $completedTraces = [];

    public function record(array $spans): array
    {
        $touchedTraces = [];
        foreach ($spans as $span) {
            $this->spansByTrace[$span->traceId][] = $span;
            $touchedTraces[$span->traceId] = true;
        }

        $completedNow = [];
        foreach (array_keys($touchedTraces) as $traceId) {
            if (isset($this->completedTraces[$traceId]) || ! $this->progressOf($traceId)->isCompleted()) {
                continue;
            }
            $this->completedTraces[$traceId] = true;
            $completedNow[] = $traceId;
        }

        return $completedNow;
    }

    public function progressOf(string $traceId): TraceProgress
    {
        return TraceLedger::progressOf($traceId, $this->spansByTrace[$traceId] ?? []);
    }

    public function removeTracesStartedBefore(DatePoint $threshold): void
    {
        foreach ($this->spansByTrace as $traceId => $spans) {
            foreach ($spans as $span) {
                if ($span->startedAt < $threshold) {
                    unset($this->spansByTrace[$traceId], $this->completedTraces[$traceId]);
                    continue 2;
                }
            }
        }
    }
}
