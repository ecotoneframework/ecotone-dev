<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanStatus;

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
    /** @var array<string, array{span: Span, flowType: string, status: string}> */
    private array $entriesByTrace = [];

    public function append(array $spans): void
    {
        $plan = FlowEntries::plan($spans, $this->storedTracesOf($spans));
        foreach ($spans as $span) {
            if (isset($this->spansByTrace[$span->traceId][$span->spanId])) {
                continue;
            }
            $this->spansByTrace[$span->traceId][$span->spanId] = $span;
            if (isset($plan->entriesBySpanId[$span->spanId])) {
                $this->entriesByTrace[$span->traceId] = ['span' => $span, ...$plan->entriesBySpanId[$span->spanId]];
            }
        }
        foreach ($plan->traceIdsToMarkFailed as $traceId) {
            $this->entriesByTrace[$traceId]['status'] = FlowEntries::STATUS_ERROR;
        }
    }

    public function flowTypeStatistics(int $limit): array
    {
        $durationsByFlowType = [];
        $failedByFlowType = [];
        $lastStartedAtByFlowType = [];
        foreach ($this->entriesByTrace as $entry) {
            $flowType = $entry['flowType'];
            $startedAt = $entry['span']->startedAt->unixTime()->inMicroseconds();
            $durationsByFlowType[$flowType][] = $entry['span']->endedAt->unixTime()->inMicroseconds() - $startedAt;
            $failedByFlowType[$flowType] = ($failedByFlowType[$flowType] ?? 0) + ($entry['status'] === FlowEntries::STATUS_ERROR ? 1 : 0);
            $lastStartedAtByFlowType[$flowType] = max($lastStartedAtByFlowType[$flowType] ?? 0, $startedAt);
        }

        $statistics = [];
        foreach ($durationsByFlowType as $flowType => $durations) {
            sort($durations);
            $runs = count($durations);
            $statistics[] = new FlowTypeStatistics(
                (string) $flowType,
                $runs,
                $failedByFlowType[$flowType],
                FlowEntries::durationInMilliseconds($durations[FlowTypeStatistics::percentileRankOffset($runs, 0.5)]),
                FlowEntries::durationInMilliseconds($durations[FlowTypeStatistics::percentileRankOffset($runs, 0.95)]),
                FlowEntries::durationInMilliseconds($durations[$runs - 1]),
                $lastStartedAtByFlowType[$flowType],
            );
        }
        usort($statistics, fn (FlowTypeStatistics $first, FlowTypeStatistics $second): int => [$second->runs, $first->flowType] <=> [$first->runs, $second->flowType]);

        return array_slice($statistics, 0, $limit);
    }

    public function tracesOf(string $flowType, int $page, int $perPage): TracePage
    {
        $entries = array_filter($this->entriesByTrace, fn (array $entry): bool => $entry['flowType'] === $flowType);
        uasort($entries, fn (array $first, array $second): int => [$second['span']->startedAt->unixTime()->inMicroseconds(), $second['span']->traceId] <=> [$first['span']->startedAt->unixTime()->inMicroseconds(), $first['span']->traceId]);

        return new TracePage(
            count($entries),
            array_values(array_map(
                fn (array $entry): TraceSummary => TraceSummary::of($entry['span'], $entry['status'], array_values($this->spansByTrace[$entry['span']->traceId])),
                array_slice($entries, ($page - 1) * $perPage, $perPage),
            )),
        );
    }

    public function progressOf(string $traceId): TraceProgress
    {
        return TraceLedger::progressOf($traceId, array_values($this->spansByTrace[$traceId] ?? []));
    }

    public function stepsOf(string $traceId): array
    {
        return array_values($this->spansByTrace[$traceId] ?? []);
    }

    public function removeTracesStartedBefore(DatePoint $startedBefore, int $batchSize): void
    {
        foreach ($this->spansByTrace as $traceId => $spans) {
            if (min(array_map(fn (Span $span): DatePoint => $span->startedAt, $spans)) < $startedBefore) {
                unset($this->spansByTrace[$traceId], $this->entriesByTrace[$traceId]);
            }
        }
    }

    public function withinTransaction(Closure $work): mixed
    {
        return $work();
    }

    /**
     * @param Span[] $spans
     * @return array<string, array{spanIds: array<string, true>, hasEntry: bool, hasError: bool}>
     */
    private function storedTracesOf(array $spans): array
    {
        $stored = [];
        foreach ($spans as $span) {
            $storedSpans = $this->spansByTrace[$span->traceId] ?? [];
            $stored[$span->traceId] = [
                'spanIds' => array_fill_keys(array_keys($storedSpans), true),
                'hasEntry' => isset($this->entriesByTrace[$span->traceId]),
                'hasError' => array_filter($storedSpans, fn (Span $storedSpan): bool => $storedSpan->status === SpanStatus::Error) !== [],
            ];
        }

        return $stored;
    }
}
