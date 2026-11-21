<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Tracing\ExecutionRecording;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\RecordedStep;
use Ecotone\Api\Tracing\Span;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingShapes
{
    /**
     * @return list<array{string, list<array{int, string, list<string>}>}>
     */
    public static function processesInAnyOrderOf(ExecutionRecording $recording): array
    {
        $processes = array_map(fn (RecordedProcess $process): array => [
            (string) preg_replace('/ \((?:part )?\d+ of \d+\)$/', '', $process->title),
            array_map(fn (RecordedStep $step): array => [$step->depth, $step->label, array_map(fn ($hint): string => $hint->key . ': ' . preg_replace('/process \d+/', 'process #', $hint->value), $step->hints)], $process->steps),
        ], $recording->processes);
        usort($processes, fn (array $first, array $second): int => serialize($first) <=> serialize($second));

        return $processes;
    }

    /**
     * @param list<Span> $spans
     * @return list<list<array{string, ?string, string}>>
     */
    public static function traceShapesInAnyOrderOf(array $spans): array
    {
        $byId = [];
        foreach ($spans as $span) {
            $byId[$span->spanId] = $span;
        }
        $traces = [];
        foreach ($spans as $span) {
            $traces[$span->traceId][] = [$span->name, isset($byId[(string) $span->parentSpanId]) ? $byId[(string) $span->parentSpanId]->name : null, $span->status->name];
        }
        $shapes = array_map(function (array $trace): array {
            sort($trace);

            return $trace;
        }, array_values($traces));
        usort($shapes, fn (array $first, array $second): int => serialize($first) <=> serialize($second));

        return $shapes;
    }
}
