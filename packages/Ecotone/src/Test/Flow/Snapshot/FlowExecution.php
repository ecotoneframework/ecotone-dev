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
    private const PLUMBING = ['Bridge::handle', 'RoutingSlipPrepender::transform', 'HeaderRouter::route', 'GatheringMessageHandler::handle'];
    private const GENERATED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{64}/';

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

        [$links, $sources] = self::linksBetween($processes, $children);
        $processes = array_map(
            fn (Span $process): array => ($process->attributes[SpanAttribute::EXECUTION_STEP] ?? false)
                ? ['process' => self::nameOf(self::firstSpanUnder($process, $children) ?? $process), 'steps' => [...self::linesOf([$process], $children, 1, $links)]]
                : ['process' => self::nameOf($process), 'steps' => self::stepsUnder($process, $children, 1, $links)],
            $processes,
        );

        return new self(self::withNumberedGeneratedNames(self::titled($processes, $sources)));
    }

    /**
     * @param list<Span> $processes
     * @param array<string, list<Span>> $children
     * @return array{0: array<string, string>, 1: array<int, int>} what each sending and receiving step adds to its line, and the process that put the received message of each consumer process on its channel
     */
    private static function linksBetween(array $processes, array $children): array
    {
        $processOf = [];
        $parentOf = [];
        $messageSteps = [];
        foreach ($processes as $position => $process) {
            $pending = [$process];
            while ($pending !== []) {
                $span = array_shift($pending);
                $processOf[$span->spanId] = $position;
                if (isset($span->attributes[SpanAttribute::EXECUTION_SENT_MESSAGE]) || isset($span->attributes[SpanAttribute::EXECUTION_RECEIVED_MESSAGE])) {
                    $messageSteps[] = $span;
                }
                foreach ($children[$span->spanId] ?? [] as $child) {
                    $parentOf[$child->spanId] = $span;
                    $pending[] = $child;
                }
            }
        }
        usort($messageSteps, fn (Span $first, Span $second): int => self::orderOf($first) <=> self::orderOf($second));
        $links = [];
        $sources = [];
        $sendersSinceLastReceive = [];
        foreach ($messageSteps as $span) {
            if (isset($span->attributes[SpanAttribute::EXECUTION_SENT_MESSAGE])) {
                $sendersSinceLastReceive[(string) $span->attributes[SpanAttribute::EXECUTION_SENT_MESSAGE]][] = $span;
                continue;
            }
            $message = (string) $span->attributes[SpanAttribute::EXECUTION_RECEIVED_MESSAGE];
            $senders = $sendersSinceLastReceive[$message] ?? [];
            unset($sendersSinceLastReceive[$message]);
            if ($senders === []) {
                continue;
            }
            $consumer = $processOf[$span->spanId];
            $putter = $senders[count($senders) - 1];
            $sources[$consumer] = $processOf[$putter->spanId] + 1;
            $links[$span->spanId] = ': ' . self::kindOfMessageSentBy($senders[0], $parentOf) . ', put on the channel by process ' . $sources[$consumer];
            $links[$putter->spanId] = '; taken from the channel later by a separate consumer run, process ' . ($consumer + 1);
        }

        return [$links, $sources];
    }

    /**
     * @param array<string, Span> $parentOf
     */
    private static function kindOfMessageSentBy(Span $sender, array $parentOf): string
    {
        for ($span = $sender; $span !== null; $span = $parentOf[$span->spanId] ?? null) {
            if (preg_match('/^part report by .*, part (\d+ of \d+), to /', self::nameOf($span), $part) === 1) {
                return 'the part report for part ' . $part[1];
            }
            if (preg_match('/^send (command|event|query) (\S+)$/', self::nameOf($span), $sent) === 1) {
                return 'the ' . $sent[1] . ' ' . $sent[2];
            }
            if (preg_match('/^entrypoint sends to ([^,]+)/', self::nameOf($span), $target) === 1) {
                return 'the message the entrypoint sent to ' . $target[1];
            }
        }

        return 'the message';
    }

    /**
     * @param list<array{process: string, steps: list<string>}> $processes
     * @return list<array{process: string, steps: list<string>}>
     */
    private static function withNumberedGeneratedNames(array $processes): array
    {
        $numbers = [];
        $numbered = function (string $text) use (&$numbers): string {
            return (string) preg_replace_callback(self::GENERATED_ID, function (array $generated) use (&$numbers): string {
                $numbers[$generated[0]] ??= count($numbers) + 1;

                return '{generated#' . $numbers[$generated[0]] . '}';
            }, $text);
        };
        foreach ($processes as $position => $process) {
            $processes[$position]['process'] = $numbered($process['process']);
            $processes[$position]['steps'] = array_map($numbered, $process['steps']);
        }

        return $processes;
    }

    /**
     * @param list<array{process: string, steps: list<string>}> $processes
     * @param array<int, int> $sources
     * @return list<array{process: string, steps: list<string>}>
     */
    private static function titled(array $processes, array $sources): array
    {
        foreach ($processes as $position => $process) {
            $handled = self::handledIn($process['steps'], $sources[$position] ?? null);
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
    private static function handledIn(array $steps, ?int $source): ?string
    {
        foreach ($steps as $step) {
            if (preg_match('/^\s*(?:handler|transformer|splitter|router|orchestrator) (\S+::\w+)/', $step, $handler) === 1 && ! in_array($handler[1], self::PLUMBING, true)) {
                return $handler[1];
            }
            if (preg_match('/^\s*read part (\d+ of \d+) of gathering group /', $step, $part) === 1) {
                return 'the gatherer only, recording part ' . $part[1] . ($source === null ? '' : ' reported by process ' . $source . ', whose work ran there');
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
     * @param array<string, string> $links
     * @return list<string>
     */
    private static function stepsUnder(Span $span, array $children, int $depth, array $links): array
    {
        return self::linesOf($children[$span->spanId] ?? [], $children, $depth, $links);
    }

    /**
     * @param list<Span> $spans
     * @param array<string, list<Span>> $children
     * @param array<string, string> $links
     * @return list<string>
     */
    private static function linesOf(array $spans, array $children, int $depth, array $links): array
    {
        $steps = [];
        foreach ($spans as $child) {
            $outcome = $child->attributes[SpanAttribute::EXECUTION_OUTCOME] ?? '';
            $steps[] = str_repeat('  ', $depth) . (($child->attributes[SpanAttribute::EXECUTION_STEP] ?? false) ? '' : 'span ') . self::nameOf($child) . ($outcome === '' ? '' : ' ' . $outcome) . ($links[$child->spanId] ?? '');
            $steps = [...$steps, ...self::stepsUnder($child, $children, $depth + 1, $links)];
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
