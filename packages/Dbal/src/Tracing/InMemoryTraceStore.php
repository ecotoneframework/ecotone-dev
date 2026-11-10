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
    /** @var array<string, array<string, array{span: Span, flowType: string, status: string}>> */
    private array $entriesByTrace = [];

    public function append(array $spans, ?string $onlyPossibleEntrySpanId): void
    {
        $plan = FlowEntries::plan(spans: $spans, storedTraces: $this->storedTracesOf($spans), onlyPossibleEntrySpanId: $onlyPossibleEntrySpanId);
        foreach ($spans as $span) {
            $stored = $this->spansByTrace[$span->traceId][$span->spanId] ?? null;
            if ($stored !== null) {
                if ($span->status === SpanStatus::Error && $stored->status !== SpanStatus::Error) {
                    $this->spansByTrace[$span->traceId][$span->spanId] = new Span(...[...get_object_vars($stored), 'status' => $span->status, 'statusDescription' => $span->statusDescription, 'attributes' => $span->attributes]);
                }

                continue;
            }
            $this->spansByTrace[$span->traceId][$span->spanId] = $span;
            if (isset($plan->entriesBySpanId[$span->spanId])) {
                $this->entriesByTrace[$span->traceId][$span->spanId] = ['span' => $span, ...$plan->entriesBySpanId[$span->spanId]];
            }
        }
        foreach ($plan->entrySpanIdsToMarkFailedByTrace as $traceId => $spanIds) {
            foreach ($spanIds as $spanId) {
                $this->entriesByTrace[$traceId][$spanId]['status'] = FlowEntries::STATUS_ERROR;
            }
        }
    }

    public function appendingOnCurrentConnection(): Closure
    {
        return $this->append(...);
    }

    public function flowTypeStatistics(int $limit, int $window): array
    {
        $entriesByFlowType = [];
        foreach ($this->entries() as $entry) {
            $startedAt = $entry['span']->startedAt->unixTime()->inMicroseconds();
            $entriesByFlowType[$entry['flowType']][] = [
                'startedAt' => $startedAt,
                'durationInMicroseconds' => $entry['span']->endedAt->unixTime()->inMicroseconds() - $startedAt,
                'status' => $entry['status'],
                'traceId' => $entry['span']->traceId,
                'spanId' => $entry['span']->spanId,
            ];
        }

        $statistics = [];
        foreach ($entriesByFlowType as $flowType => $entries) {
            usort($entries, fn (array $first, array $second): int => [$second['startedAt'], $second['traceId'], $second['spanId']] <=> [$first['startedAt'], $first['traceId'], $first['spanId']]);
            $statistics[] = FlowTypeStatistics::overWindow((string) $flowType, count($entries), array_slice($entries, 0, $window));

        }
        $statistics = array_values(array_filter($statistics));
        usort($statistics, fn (FlowTypeStatistics $first, FlowTypeStatistics $second): int => [$second->runs, $first->flowType] <=> [$first->runs, $second->flowType]);

        return array_slice($statistics, 0, $limit);
    }

    public function tracesOf(string $flowType, int $page, int $perPage): TracePage
    {
        $entries = array_filter($this->entries(), fn (array $entry): bool => $entry['flowType'] === $flowType);
        $order = fn (array $entry): array => [$entry['span']->startedAt->unixTime()->inMicroseconds(), $entry['span']->traceId, $entry['span']->spanId];
        usort($entries, fn (array $first, array $second): int => $order($second) <=> $order($first));

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
     * @return array<int, array{span: Span, flowType: string, status: string}>
     */
    private function entries(): array
    {
        return array_merge(...array_values(array_map(array_values(...), $this->entriesByTrace)));
    }

    /**
     * @param Span[] $spans
     * @return array<string, array<string, array{parentSpanId: ?string, failed: bool, entryStatus: ?string}>>
     */
    private function storedTracesOf(array $spans): array
    {
        $stored = [];
        foreach ($spans as $span) {
            $stored[$span->traceId] ??= array_map(fn (Span $storedSpan): array => [
                'parentSpanId' => $storedSpan->parentSpanId,
                'failed' => $storedSpan->status === SpanStatus::Error,
                'entryStatus' => $this->entriesByTrace[$span->traceId][$storedSpan->spanId]['status'] ?? null,
            ], $this->spansByTrace[$span->traceId] ?? []);
        }

        return $stored;
    }
}
