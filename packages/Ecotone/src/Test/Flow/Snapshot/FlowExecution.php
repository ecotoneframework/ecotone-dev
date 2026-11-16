<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Test\Flow\ExpectedSpan;
use Ecotone\Tracing\Execution\ExecutionHint;

/**
 * licence Apache-2.0
 */
final class FlowExecution
{
    private const PLUMBING = ['Bridge::handle', 'RoutingSlipPrepender::transform', 'HeaderRouter::route'];
    private const GENERATED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{64}/';
    private const HINT_MARKER = '↳ ';

    /**
     * @param list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}>}> $processes
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
                ? ['process' => self::nameOf(self::firstSpanUnder($process, $children) ?? $process), 'steps' => self::stepsOf([$process], $children, 1, $links)]
                : ['process' => self::nameOf($process), 'steps' => self::stepsOf($children[$process->spanId] ?? [], $children, 1, $links)],
            $processes,
        );

        return new self(self::withNumberedGeneratedNames(self::titled($processes, $sources)));
    }

    /**
     * @return list<string>
     */
    public function lines(bool $withEveryHint = false): array
    {
        $lines = [];
        foreach ($this->processes as $position => $process) {
            $lines[] = 'process ' . ($position + 1) . ': ' . $process['process'];
            foreach ($process['steps'] as $step) {
                $lines[] = str_repeat('  ', $step['depth']) . $step['label'];
                foreach ($step['hints'] as $hint) {
                    if ($withEveryHint || $hint['critical']) {
                        $lines[] = self::hintLine($step['depth'], $hint['key'], $hint['value']);
                    }
                }
            }
        }

        return $lines;
    }

    public static function hintLine(int $depth, string $key, string $value): string
    {
        return str_repeat('  ', $depth + 1) . self::HINT_MARKER . $key . ': ' . $value;
    }

    /**
     * @param list<Span> $processes
     * @param array<string, list<Span>> $children
     * @return array{0: array<string, list<array{key: string, value: string, critical: bool}>>, 1: array<int, array{process: int, part: ?string}>} hints each sending and receiving step gets, and the process that put the received message of each consumer process on its channel
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
            [$kind, $part] = self::kindOfMessageSentBy($senders[0], $parentOf);
            $sources[$consumer] = ['process' => $processOf[$putter->spanId] + 1, 'part' => $part];
            $links[$span->spanId][] = ['key' => ExecutionHint::SENT_BY, 'value' => 'process ' . $sources[$consumer]['process'] . ', ' . $kind, 'critical' => true];
            $links[$putter->spanId][] = ['key' => ExecutionHint::TAKEN_BY, 'value' => 'process ' . ($consumer + 1) . ', a separate consumer run', 'critical' => true];
        }

        return [$links, $sources];
    }

    /**
     * @param array<string, Span> $parentOf
     * @return array{0: string, 1: ?string}
     */
    private static function kindOfMessageSentBy(Span $sender, array $parentOf): array
    {
        for ($span = $sender; $span !== null; $span = $parentOf[$span->spanId] ?? null) {
            $name = self::nameOf($span);
            if (str_starts_with($name, 'part report to ')) {
                foreach (self::hintsOf($span) as $hint) {
                    if ($hint['key'] === ExecutionHint::RESULT && preg_match('/^part (\d+ of \d+)/', $hint['value'], $part) === 1) {
                        return ['the part report for part ' . $part[1], $part[1]];
                    }
                }
            }
            if (preg_match('/^send (command|event|query) (\S+)$/', $name, $sent) === 1) {
                return ['the ' . $sent[1] . ' ' . $sent[2], null];
            }
            if (preg_match('/^entrypoint sends to (\S+)/', $name, $target) === 1) {
                return ['the message the entrypoint sent to ' . $target[1], null];
            }
        }

        return ['the message', null];
    }

    /**
     * @return list<array{key: string, value: string, critical: bool}>
     */
    private static function hintsOf(Span $span): array
    {
        $hints = [];
        $outcome = (string) ($span->attributes[SpanAttribute::EXECUTION_OUTCOME] ?? '');
        if ($outcome !== '' && self::thrownBy($span) === null) {
            $hints[] = ['key' => ExecutionHint::RESULT, 'value' => self::readable($outcome), 'critical' => false];
        }
        foreach (json_decode((string) ($span->attributes[SpanAttribute::EXECUTION_HINTS] ?? '[]'), true, flags: JSON_THROW_ON_ERROR) as [$key, $value, $critical]) {
            $hints[] = ['key' => (string) $key, 'value' => self::readable((string) $value), 'critical' => (bool) $critical];
        }

        return $hints;
    }

    private static function thrownBy(Span $span): ?string
    {
        $outcome = (string) ($span->attributes[SpanAttribute::EXECUTION_OUTCOME] ?? '');

        return str_starts_with($outcome, 'throws ') ? substr($outcome, strlen('throws ')) : null;
    }

    /**
     * @param array<string, list<Span>> $children
     */
    private static function aStepUnderThrows(Span $span, array $children, string $failure): bool
    {
        foreach ($children[$span->spanId] ?? [] as $child) {
            if (($child->attributes[SpanAttribute::EXECUTION_STEP] ?? false) ? self::thrownBy($child) === $failure : self::aStepUnderThrows($child, $children, $failure)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<Span>> $children
     * @return list<array{key: string, value: string, critical: bool}>
     */
    private static function failureHintsOf(Span $span, array $children, ?string $failureAbove, bool $hasStepAbove): array
    {
        $failure = self::thrownBy($span);
        if ($failure === null && $span->status === SpanStatus::Error) {
            return [];
        }
        if ($failure === null) {
            foreach (self::failuresOfStepsUnder($span, $children) as $caught) {
                return [['key' => ExecutionHint::RESULT, 'value' => 'caught ' . $caught, 'critical' => true]];
            }

            return [];
        }
        if (! self::aStepUnderThrows($span, $children, $failure)) {
            return [['key' => ExecutionHint::RESULT, 'value' => 'throws ' . $failure, 'critical' => true]];
        }
        if ($failureAbove === $failure || $hasStepAbove) {
            return [['key' => ExecutionHint::RESULT, 'value' => 'passes ' . $failure . ' on', 'critical' => false]];
        }

        return [['key' => ExecutionHint::RESULT, 'value' => 'passes ' . $failure . ' out of the process', 'critical' => true]];
    }

    /**
     * @param array<string, list<Span>> $children
     * @return list<string>
     */
    private static function failuresOfStepsUnder(Span $span, array $children): array
    {
        $failures = [];
        foreach ($children[$span->spanId] ?? [] as $child) {
            if ($child->attributes[SpanAttribute::EXECUTION_STEP] ?? false) {
                $failure = self::thrownBy($child);
                if ($failure !== null) {
                    $failures[] = $failure;
                }
                continue;
            }
            $failures = [...$failures, ...self::failuresOfStepsUnder($child, $children)];
        }

        return $failures;
    }

    /**
     * @param list<Span> $spans
     * @param array<string, list<Span>> $children
     * @param array<string, list<array{key: string, value: string, critical: bool}>> $links
     * @return list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}>
     */
    private static function stepsOf(array $spans, array $children, int $depth, array $links, ?string $failureAbove = null, bool $hasStepAbove = false, string $parent = ''): array
    {
        $steps = [];
        $previous = null;
        $attempt = 1;
        foreach ($spans as $child) {
            $isStep = (bool) ($child->attributes[SpanAttribute::EXECUTION_STEP] ?? false);
            $label = ($isStep ? '' : 'span ') . self::nameOf($child);
            $retried = $previous !== null && $previous['label'] === $label && $previous['failure'] !== null;
            $attempt = $retried ? $attempt + 1 : 1;
            $steps[] = [
                'depth' => $depth,
                'label' => $label,
                'hints' => [
                    ...($retried ? [['key' => ExecutionHint::RETRY, 'value' => 'attempt ' . $attempt . ' after ' . $previous['failure'] . ($parent === '' ? '' : ', by ' . self::withoutKind($parent)), 'critical' => true]] : []),
                    ...($isStep ? self::failureHintsOf($child, $children, $failureAbove, $hasStepAbove && $failureAbove === null) : []),
                    ...self::hintsOf($child),
                    ...($links[$child->spanId] ?? []),
                ],
            ];
            $failure = self::thrownBy($child) ?? ($child->status === SpanStatus::Error ? 'a failure' : null);
            $steps = [...$steps, ...self::stepsOf($children[$child->spanId] ?? [], $children, $depth + 1, $links, $isStep ? $failure : $failureAbove, $hasStepAbove || $isStep, $isStep ? $label : $parent)];
            $previous = ['label' => $label, 'failure' => $failure];
        }

        return $steps;
    }

    private static function withoutKind(string $label): string
    {
        $words = explode(' ', $label, 2);

        return str_contains($words[1] ?? '', '::') ? $words[1] : $label;
    }

    /**
     * @param list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}>}> $processes
     * @return list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}>}>
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
            foreach ($process['steps'] as $index => $step) {
                $processes[$position]['steps'][$index]['label'] = $numbered($step['label']);
                foreach ($step['hints'] as $hintIndex => $hint) {
                    $processes[$position]['steps'][$index]['hints'][$hintIndex]['value'] = $numbered($hint['value']);
                }
            }
        }

        return $processes;
    }

    /**
     * @param list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}>}> $processes
     * @param array<int, array{process: int, part: ?string}> $sources
     * @return list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}>}>
     */
    private static function titled(array $processes, array $sources): array
    {
        $ordinals = [];
        foreach ($processes as $position => $process) {
            [$handled, $part] = self::handledIn($process['steps']);
            $processes[$position]['process'] = $process['process'] . ($handled === null ? '' : ', ' . $handled);
            if ($part !== null) {
                $ordinals[$position] = 'part ' . $part;
                if (isset($sources[$position]) && $process['steps'] !== []) {
                    $processes[$position]['steps'][0]['hints'][] = ['key' => ExecutionHint::RUNS, 'value' => 'only the gatherer, the work of part ' . $part . ' ran in process ' . $sources[$position]['process'], 'critical' => true];
                }
            }
        }
        $count = array_count_values(array_column($processes, 'process'));
        $seen = [];
        foreach ($processes as $position => $process) {
            if ($count[$process['process']] > 1) {
                $seen[$process['process']] = ($seen[$process['process']] ?? 0) + 1;
                $ordinals[$position] ??= $seen[$process['process']] . ' of ' . $count[$process['process']];
            }
        }
        foreach ($ordinals as $position => $ordinal) {
            $processes[$position]['process'] .= ' (' . $ordinal . ')';
        }

        return $processes;
    }

    /**
     * @param list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}> $steps
     * @return array{0: ?string, 1: ?string} the handler the process ran, and the part when it is the gatherer
     */
    private static function handledIn(array $steps): array
    {
        foreach ($steps as $position => $step) {
            if (preg_match('/^(?:handler|transformer|splitter|router|orchestrator) (\S+::\w+)$/', $step['label'], $handler) !== 1 || in_array($handler[1], self::PLUMBING, true)) {
                continue;
            }
            $read = $steps[$position + 1]['label'] ?? '';

            return [$handler[1], preg_match('/^read part (\d+ of \d+)$/', $read, $part) === 1 ? $part[1] : null];
        }

        return [null, null];
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
