<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Tracing\ExecutionRecording;
use Ecotone\Api\Tracing\RecordedHint;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\RecordedSender;
use Ecotone\Api\Tracing\RecordedStep;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanStatus;

/**
 * licence Apache-2.0
 */
final class RecordedExecution
{
    public const GENERATED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{64}/';
    private const HEADERS_PROPAGATION_SPAN = 'process MessageHeadersPropagatorInterceptor::';
    private const PLUMBING = ['Bridge::handle', 'RoutingSlipPrepender::transform', 'HeaderRouter::route'];
    private const HINT_MARKER = '↳ ';

    /**
     * @param list<array{process: string, sentBy?: array{processNumber: int, stepNumber: int}, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}> $processes
     */
    private function __construct(public readonly array $processes)
    {
    }

    /**
     * @param Span[] $spans
     */
    public static function of(array $spans): self
    {
        $spans = array_values(array_filter($spans, fn (Span $span): bool => ! str_starts_with($span->name, self::HEADERS_PROPAGATION_SPAN) && isset($span->attributes[SpanAttribute::EXECUTION_ORDER])));
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

        [$links, $sentBy] = self::linksBetween($processes, $children);
        $processes = array_map(
            fn (Span $process, int $position): array => [
                ...(($process->attributes[SpanAttribute::EXECUTION_STEP] ?? false)
                    ? ['process' => self::nameOf(self::firstSpanUnder($process, $children) ?? $process), 'steps' => self::stepsOf(spans: [$process], children: $children, depth: 1, links: $links)]
                    : ['process' => self::nameOf($process), 'steps' => self::stepsOf(spans: $children[$process->spanId] ?? [], children: $children, depth: 1, links: $links)]),
                ...(isset($sentBy[$position]) ? ['sentBy' => $sentBy[$position]] : []),
            ],
            $processes,
            array_keys($processes),
        );

        return new self(self::withNumberedGeneratedNames(self::titled($processes)));
    }

    public function toRecording(): ExecutionRecording
    {
        return new ExecutionRecording(
            processes: array_map(fn (array $process): RecordedProcess => new RecordedProcess(
                title: $process['process'],
                steps: array_map(fn (array $step): RecordedStep => new RecordedStep(
                    depth: $step['depth'],
                    label: $step['label'],
                    hints: array_map(fn (array $hint): RecordedHint => new RecordedHint(key: $hint['key'], value: $hint['value'], shownInLines: $hint['shownInLines']), $step['hints']),
                ), $process['steps']),
                sentBy: isset($process['sentBy']) ? new RecordedSender(processNumber: $process['sentBy']['processNumber'], stepNumber: $process['sentBy']['stepNumber']) : null,
            ), $this->processes),
            lines: $this->lines(),
            linesWithEveryHint: $this->lines(true),
        );
    }

    /**
     * @return list<string>
     */
    public function lines(bool $withEveryHint = false): array
    {
        $subFlows = self::subFlowsOf($this->processes);
        $lines = [];
        foreach (array_keys($this->processes) as $position) {
            if (! self::isSubFlow($this->processes, $position)) {
                $lines = [...$lines, ...$this->linesOfFlow(position: $position, level: 0, subFlows: $subFlows, withEveryHint: $withEveryHint)];
            }
        }

        return $lines;
    }

    /**
     * @param list<array{process: string, sentBy?: array{processNumber: int, stepNumber: int}, steps: list<array<string, mixed>>}> $processes
     * @return array<int, list<int>>
     */
    public static function subFlowsOf(array $processes): array
    {
        $subFlows = [];
        foreach (array_keys($processes) as $position) {
            if (self::isSubFlow($processes, $position)) {
                $subFlows[$processes[$position]['sentBy']['processNumber'] - 1][] = $position;
            }
        }
        foreach ($subFlows as $sender => $positions) {
            usort($positions, fn (int $first, int $second): int => [$processes[$first]['sentBy']['stepNumber'], $first] <=> [$processes[$second]['sentBy']['stepNumber'], $second]);
            $subFlows[$sender] = $positions;
        }

        return $subFlows;
    }

    /**
     * @param list<array{process: string, sentBy?: array{processNumber: int, stepNumber: int}, steps: list<array<string, mixed>>}> $processes
     */
    public static function isSubFlow(array $processes, int $position): bool
    {
        $sender = ($processes[$position]['sentBy']['processNumber'] ?? 0) - 1;

        return $sender >= 0 && $sender < $position;
    }

    /**
     * @param array<int, list<int>> $subFlows
     * @return list<string>
     */
    private function linesOfFlow(int $position, int $level, array $subFlows, bool $withEveryHint): array
    {
        $indent = str_repeat('  ', $level);
        $process = $this->processes[$position];
        $lines = [$indent . ($level === 0 ? '' : 'sub-flow ') . 'process ' . ($position + 1) . ': ' . $process['process']];
        foreach ($process['steps'] as $step) {
            $lines[] = $indent . str_repeat('  ', $step['depth']) . $step['label'];
            foreach ($step['hints'] as $hint) {
                if ($withEveryHint || $hint['shownInLines']) {
                    $lines[] = $indent . self::hintLine(depth: $step['depth'], key: $hint['key'], value: $hint['value']);
                }
            }
        }
        foreach ($subFlows[$position] ?? [] as $subFlow) {
            $lines = [...$lines, ...$this->linesOfFlow(position: $subFlow, level: $level + 1, subFlows: $subFlows, withEveryHint: $withEveryHint)];
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
     * @return array{0: array<string, list<array{key: string, value: string, shownInLines: bool}>>, 1: array<int, array{processNumber: int, stepNumber: int}>}
     */
    private static function linksBetween(array $processes, array $children): array
    {
        $processOf = [];
        $parentOf = [];
        $stepOf = [];
        $spanOf = [];
        $inStepOrder = [];
        foreach ($processes as $position => $process) {
            $inStepOrder[$position] = self::inStepOrder(($process->attributes[SpanAttribute::EXECUTION_STEP] ?? false) ? [$process] : ($children[$process->spanId] ?? []), $children);
            foreach ($inStepOrder[$position] as $index => $span) {
                $stepOf[$span->spanId] = $index;
            }
            $pending = [$process];
            while ($pending !== []) {
                $span = array_shift($pending);
                $processOf[$span->spanId] = $position;
                $spanOf[$span->spanId] = $span;
                foreach ($children[$span->spanId] ?? [] as $child) {
                    $parentOf[$child->spanId] = $span;
                    $pending[] = $child;
                }
            }
        }
        $links = [];
        $sentBy = [];
        foreach ($processes as $consumer => $process) {
            $sender = self::senderOf(spans: [$process, ...$inStepOrder[$consumer]], processOf: $processOf, consumer: $consumer);
            $startingStep = self::stepThatStartedTheCoroutineOf(process: $process, spanOf: $spanOf, stepOf: $stepOf);
            if ($sender === null && $startingStep !== null && $inStepOrder[$consumer] !== [] && $processOf[$startingStep->spanId] !== $consumer) {
                $starter = $processOf[$startingStep->spanId];
                $sentBy[$consumer] = ['processNumber' => $starter + 1, 'stepNumber' => $stepOf[$startingStep->spanId] + 1];
                $links[$inStepOrder[$consumer][0]->spanId][] = ['key' => ExecutionHint::STARTED_IN, 'value' => 'a coroutine started by process ' . ($starter + 1), 'shownInLines' => true];
                $links[$startingStep->spanId][] = ['key' => ExecutionHint::STARTS, 'value' => 'process ' . ($consumer + 1) . ', in a coroutine', 'shownInLines' => true];
                continue;
            }
            if ($sender === null) {
                $firstStep = $inStepOrder[$consumer][0] ?? null;
                $sentFrom = $firstStep?->attributes[SpanAttribute::EXECUTION_SENT_FROM_ANOTHER_APPLICATION] ?? null;
                if ($sentFrom !== null) {
                    $links[$firstStep->spanId][] = ['key' => ExecutionHint::SENT_BY, 'value' => (string) $sentFrom, 'shownInLines' => true];
                }
                continue;
            }
            $sendingStep = self::sendingStepOf(sender: $spanOf[$sender], parentOf: $parentOf, stepOf: $stepOf);
            if ($sendingStep === null || $inStepOrder[$consumer] === []) {
                continue;
            }
            $producer = $processOf[$sender];
            [$kind] = self::kindOfMessageSentBy(self::firstSendOf($sendingStep, $inStepOrder[$producer]), $parentOf);
            $sentBy[$consumer] = ['processNumber' => $producer + 1, 'stepNumber' => $stepOf[$sendingStep->spanId] + 1];
            $links[$inStepOrder[$consumer][0]->spanId][] = ['key' => ExecutionHint::SENT_BY, 'value' => 'process ' . ($producer + 1) . ', ' . $kind, 'shownInLines' => true];
            $links[$sendingStep->spanId][] = ['key' => ExecutionHint::TAKEN_BY, 'value' => 'process ' . ($consumer + 1) . ', a separate consumer run', 'shownInLines' => true];
        }

        return [$links, $sentBy];
    }

    /**
     * @param array<string, Span> $spanOf
     * @param array<string, int> $stepOf
     */
    private static function stepThatStartedTheCoroutineOf(Span $process, array $spanOf, array $stepOf): ?Span
    {
        $order = $process->attributes[SpanAttribute::EXECUTION_STARTED_IN_A_COROUTINE_OF] ?? null;
        if ($order === null) {
            return null;
        }
        foreach ($spanOf as $span) {
            if (isset($stepOf[$span->spanId]) && ($span->attributes[SpanAttribute::EXECUTION_ORDER] ?? null) === $order) {
                return $span;
            }
        }

        return null;
    }

    /**
     * @param list<Span> $spans
     * @param array<string, int> $processOf
     */
    private static function senderOf(array $spans, array $processOf, int $consumer): ?string
    {
        foreach ($spans as $span) {
            if ($span->parentSpanId !== null && isset($processOf[$span->parentSpanId]) && $processOf[$span->parentSpanId] !== $consumer) {
                return $span->parentSpanId;
            }
        }

        return null;
    }

    /**
     * @param array<string, Span> $parentOf
     * @param array<string, int> $stepOf
     */
    private static function sendingStepOf(Span $sender, array $parentOf, array $stepOf): ?Span
    {
        $nearestStep = null;
        for ($span = $sender; $span !== null; $span = $parentOf[$span->spanId] ?? null) {
            if (isset($span->attributes[SpanAttribute::EXECUTION_SENT_MESSAGE]) && isset($stepOf[$span->spanId])) {
                return $span;
            }
            $nearestStep ??= isset($stepOf[$span->spanId]) ? $span : null;
        }

        return $nearestStep;
    }

    /**
     * @param list<Span> $spans
     */
    private static function firstSendOf(Span $sendingStep, array $spans): Span
    {
        $message = $sendingStep->attributes[SpanAttribute::EXECUTION_SENT_MESSAGE] ?? null;
        foreach ($message === null ? [] : $spans as $span) {
            if (($span->attributes[SpanAttribute::EXECUTION_SENT_MESSAGE] ?? null) === $message) {
                return $span;
            }
        }

        return $sendingStep;
    }

    /**
     * @param list<Span> $spans
     * @param array<string, list<Span>> $children
     * @return list<Span>
     */
    private static function inStepOrder(array $spans, array $children): array
    {
        $ordered = [];
        foreach ($spans as $span) {
            $ordered = [...$ordered, $span, ...self::inStepOrder($children[$span->spanId] ?? [], $children)];
        }

        return $ordered;
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
                    if ($hint['key'] === ExecutionHint::OUTCOME && preg_match('/^part (\d+ of \d+)/', $hint['value'], $part) === 1) {
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
     * @return list<array{key: string, value: string, shownInLines: bool}>
     */
    private static function hintsOf(Span $span): array
    {
        $hints = [];
        $outcome = (string) ($span->attributes[SpanAttribute::EXECUTION_OUTCOME] ?? '');
        if ($outcome !== '' && self::thrownBy($span) === null) {
            $hints[] = str_starts_with($outcome, 'returns ')
                ? ['key' => ExecutionHint::RETURNS, 'value' => self::readable(substr($outcome, strlen('returns '))), 'shownInLines' => false]
                : ['key' => ExecutionHint::OUTCOME, 'value' => self::readable($outcome), 'shownInLines' => false];
        }
        foreach (json_decode((string) ($span->attributes[SpanAttribute::EXECUTION_HINTS] ?? '[]'), true, flags: JSON_THROW_ON_ERROR) as [$key, $value, $shownInLines]) {
            $hints[] = ['key' => (string) $key, 'value' => self::readable((string) $value), 'shownInLines' => (bool) $shownInLines];
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
            if (($child->attributes[SpanAttribute::EXECUTION_STEP] ?? false) ? self::thrownBy($child) === $failure : self::aStepUnderThrows(span: $child, children: $children, failure: $failure)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<Span>> $children
     * @return list<array{key: string, value: string, shownInLines: bool}>
     */
    private static function failureHintsOf(Span $span, array $children, ?string $failureAbove, bool $hasStepAbove): array
    {
        $failure = self::thrownBy($span);
        if ($failure === null && $span->status === SpanStatus::Error) {
            return [];
        }
        if ($failure === null) {
            foreach (self::failuresOfStepsUnder($span, $children) as $caught) {
                return [['key' => ExecutionHint::FAILURE, 'value' => 'caught ' . $caught, 'shownInLines' => true]];
            }

            return [];
        }
        if (! self::aStepUnderThrows(span: $span, children: $children, failure: $failure)) {
            return [['key' => ExecutionHint::FAILURE, 'value' => 'throws ' . $failure, 'shownInLines' => true]];
        }
        if ($failureAbove === $failure || $hasStepAbove) {
            return [['key' => ExecutionHint::FAILURE, 'value' => 'passes ' . $failure . ' on', 'shownInLines' => false]];
        }

        return [['key' => ExecutionHint::FAILURE, 'value' => 'passes ' . $failure . ' out of the process', 'shownInLines' => true]];
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
     * @param array<string, list<array{key: string, value: string, shownInLines: bool}>> $links
     * @return list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>
     */
    private static function stepsOf(array $spans, array $children, int $depth, array $links, ?string $failureAbove = null, bool $hasStepAbove = false): array
    {
        $steps = [];
        foreach ($spans as $child) {
            $isStep = (bool) ($child->attributes[SpanAttribute::EXECUTION_STEP] ?? false);
            $label = ($isStep ? '' : 'span ') . self::nameOf($child);
            $steps[] = [
                'depth' => $depth,
                'label' => $label,
                'hints' => [
                    ...($isStep ? self::failureHintsOf(span: $child, children: $children, failureAbove: $failureAbove, hasStepAbove: $hasStepAbove && $failureAbove === null) : []),
                    ...self::hintsOf($child),
                    ...($links[$child->spanId] ?? []),
                ],
            ];
            $failure = self::thrownBy($child) ?? ($child->status === SpanStatus::Error ? 'a failure' : null);
            $steps = [...$steps, ...self::stepsOf(spans: $children[$child->spanId] ?? [], children: $children, depth: $depth + 1, links: $links, failureAbove: $isStep ? $failure : $failureAbove, hasStepAbove: $hasStepAbove || $isStep)];
        }

        return $steps;
    }

    /**
     * @param list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}> $processes
     * @return list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}>
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
     * @param list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}> $processes
     * @return list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}>
     */
    private static function titled(array $processes): array
    {
        $ordinals = [];
        foreach ($processes as $position => $process) {
            [$handled, $part] = self::handledIn($process['steps']);
            $processes[$position]['process'] = $process['process'] . ($handled === null ? '' : ', ' . $handled);
            if ($part !== null) {
                $ordinals[$position] = 'part ' . $part;
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
     * @param list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}> $steps
     * @return array{0: ?string, 1: ?string}
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
        $name = self::readable((string) ($span->attributes[SpanAttribute::EXECUTION_LABEL] ?? $span->name));

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
