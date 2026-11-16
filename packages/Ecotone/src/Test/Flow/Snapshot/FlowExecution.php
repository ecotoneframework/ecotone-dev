<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Test\Flow\ExpectedSpan;

/**
 * licence Apache-2.0
 */
final class FlowExecution
{
    private const PLUMBING = ['Bridge::handle', 'RoutingSlipPrepender::transform'];

    /**
     * @param list<array{process: string, steps: list<string>}> $processes
     */
    private function __construct(public readonly array $processes)
    {
    }

    /**
     * @param Span[] $spans
     */
    public static function of(array $spans): self
    {
        $spans = array_values(array_filter($spans, fn (Span $span): bool => ! ExpectedSpan::isFrameworkNoise($span) && isset($span->attributes[SpanAttribute::EXECUTION_ORDER])));
        usort($spans, fn (Span $first, Span $second): int => self::orderOf($first) <=> self::orderOf($second));
        $present = [];
        foreach ($spans as $span) {
            $present[$span->spanId] = true;
        }
        $children = [];
        $processes = [];
        foreach ($spans as $span) {
            $parent = self::parentOf($span);
            if (($span->attributes[SpanAttribute::EXECUTION_PROCESS] ?? false) || $parent === null || ! isset($present[$parent])) {
                $processes[] = $span;
                continue;
            }
            $children[$parent][] = $span;
        }

        $processes = array_map(
            fn (Span $process): array => ($process->attributes[SpanAttribute::EXECUTION_STEP] ?? false)
                ? ['process' => self::nameOf(self::firstSpanUnder($process, $children) ?? $process), 'steps' => [...self::linesOf([$process], $children, 1)]]
                : ['process' => self::nameOf($process), 'steps' => self::stepsUnder($process, $children, 1)],
            $processes,
        );

        return new self(self::titled($processes));
    }

    /**
     * @param list<array{process: string, steps: list<string>}> $processes
     * @return list<array{process: string, steps: list<string>}>
     */
    private static function titled(array $processes): array
    {
        foreach ($processes as $position => $process) {
            $handled = self::handledIn($process['steps']);
            $processes[$position]['process'] = $process['process'] . ($handled === null ? '' : ', handled by ' . $handled);
        }
        $count = array_count_values(array_column($processes, 'process'));
        $seen = [];
        foreach ($processes as $position => $process) {
            if ($count[$process['process']] > 1) {
                $seen[$process['process']] = ($seen[$process['process']] ?? 0) + 1;
                $processes[$position]['process'] .= ' (' . $seen[$process['process']] . ' of ' . $count[$process['process']] . ')';
            }
        }

        return $processes;
    }

    /**
     * @param list<string> $steps
     */
    private static function handledIn(array $steps): ?string
    {
        foreach ($steps as $step) {
            if (preg_match('/^\s*handler (\S+::\w+)/', $step, $handler) === 1 && ! in_array($handler[1], self::PLUMBING, true)) {
                return $handler[1];
            }
            if (preg_match('/^\s*store part (\d+ of \d+)$/', $step, $part) === 1) {
                return 'the gatherer storing part ' . $part[1];
            }
        }

        return null;
    }

    /**
     * @param array<string, list<Span>> $children
     */
    private static function firstSpanUnder(Span $span, array $children): ?Span
    {
        foreach ($children[$span->spanId] ?? [] as $child) {
            if (! ($child->attributes[SpanAttribute::EXECUTION_STEP] ?? false)) {
                return $child;
            }
            $found = self::firstSpanUnder($child, $children);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->processes as $position => $process) {
            $lines[] = 'process ' . ($position + 1) . ': ' . $process['process'];
            foreach ($process['steps'] as $step) {
                $lines[] = $step;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, list<Span>> $children
     * @return list<string>
     */
    private static function stepsUnder(Span $span, array $children, int $depth): array
    {
        return self::linesOf($children[$span->spanId] ?? [], $children, $depth);
    }

    /**
     * @param list<Span> $spans
     * @param array<string, list<Span>> $children
     * @return list<string>
     */
    private static function linesOf(array $spans, array $children, int $depth): array
    {
        $steps = [];
        foreach ($spans as $child) {
            $outcome = $child->attributes[SpanAttribute::EXECUTION_OUTCOME] ?? '';
            $steps[] = str_repeat('  ', $depth) . (($child->attributes[SpanAttribute::EXECUTION_STEP] ?? false) ? '' : 'span ') . self::nameOf($child) . ($outcome === '' ? '' : ' ' . $outcome);
            $steps = [...$steps, ...self::stepsUnder($child, $children, $depth + 1)];
        }

        return $steps;
    }

    private static function nameOf(Span $span): string
    {
        $destination = $span->attributes[SpanAttribute::MESSAGING_DESTINATION_NAME] ?? null;
        $name = self::readable($span->name);

        return is_string($destination) && ! str_ends_with($name, ' ' . $destination) ? $name . ' ' . $destination : $name;
    }

    private static function parentOf(Span $span): ?string
    {
        $parent = $span->attributes[SpanAttribute::EXECUTION_PARENT] ?? $span->parentSpanId;

        return is_string($parent) ? $parent : null;
    }

    private static function orderOf(Span $span): int
    {
        return (int) ($span->attributes[SpanAttribute::EXECUTION_ORDER] ?? 0);
    }

    private static function readable(string $name): string
    {
        return (string) preg_replace(['/@anonymous\x00[^:]*?(?=::)/', '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/'], ['@anonymous', '{generated}'], $name);
    }
}
