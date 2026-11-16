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
        $spans = array_values(array_filter($spans, fn (Span $span): bool => ! ExpectedSpan::isFrameworkNoise($span)));
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

        return new self(array_map(fn (Span $process): array => ['process' => self::nameOf($process), 'steps' => self::stepsUnder($process, $children, 1)], $processes));
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
        $steps = [];
        foreach ($children[$span->spanId] ?? [] as $child) {
            $outcome = $child->attributes[SpanAttribute::EXECUTION_OUTCOME] ?? '';
            $steps[] = str_repeat('  ', $depth) . self::nameOf($child) . ($outcome === '' ? '' : ' ' . $outcome);
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
        return (string) preg_replace('/@anonymous\x00[^:]*?(?=::)/', '@anonymous', $name);
    }
}
