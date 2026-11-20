<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

use Ecotone\Api\Messaging\Flow\FlowComparison;
use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeChange;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeChange;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Tracing\Execution\ExecutionHint;
use Ecotone\Tracing\Execution\RecordedExecution;

/**
 * licence Apache-2.0
 */
final class FlowScenarioChange
{
    public const NODES_PER_PART = 15;
    public const CHANGED = 'changed';
    public const ADDED = 'added';
    public const REMOVED = 'removed';
    public const UNCHANGED = 'unchanged';
    public const LEFT_OUT = 'left out';
    public const MOVED = 'moved';
    private const UNCHANGED_STEPS_KEPT_WHOLE = 8;
    private const UNCHANGED_NEIGHBOURS_OF_AN_EXECUTED_STEP = 2;
    private const DERIVED = 'derivedFromWiring';
    public const PART_REPORT = 'partReport';

    /**
     * @param list<array{change: string, step: array<string, mixed>}> $trace
     * @param list<array{change: string, kind: string, name: string, before?: array<string, scalar>, after?: array<string, scalar>, chain?: list<array{change: string, layer: string}>}> $build
     * @param list<array{process: string, change: string, steps?: list<array{change: string, step?: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool}>}> $execution
     */
    private function __construct(
        public readonly string $scenario,
        public readonly string $state,
        public readonly FlowSnapshot $before,
        public readonly FlowSnapshot $after,
        public readonly FlowComparison $wiring,
        public readonly array $trace,
        public readonly array $build,
        public readonly array $execution,
    ) {
    }

    public static function between(?FlowSnapshot $before, ?FlowSnapshot $after): self
    {
        $scenario = ($after ?? $before)->scenario;
        $state = match (true) {
            $before === null => self::ADDED,
            $after === null => self::REMOVED,
            default => self::CHANGED,
        };
        $before ??= FlowSnapshot::emptyFor($scenario);
        $after ??= FlowSnapshot::emptyFor($scenario);

        return new self(
            scenario: $scenario,
            state: $state,
            before: $before,
            after: $after,
            wiring: $state === self::CHANGED ? $before->wiring->compareTo($after->wiring) : $after->wiring->compareTo($after->wiring),
            trace: self::withPartReports(trace: self::withContinuationsFromWiring(self::changesBetween($before->trace, $after->trace), $before->wiring, $after->wiring), before: $before, after: $after),
            build: $state === self::CHANGED ? self::buildChangesBetween($before, $after) : [],
            execution: self::executionChangesBetween($before->execution, $after->execution),
        );
    }

    /**
     * @return list<array{change: string, kind: string, name: string, before?: array<string, scalar>, after?: array<string, scalar>, chain?: list<array{change: string, layer: string}>}>
     */
    private static function buildChangesBetween(FlowSnapshot $before, FlowSnapshot $after): array
    {
        $changes = [];
        foreach (array_keys($before->ledger + $after->ledger) as $registration) {
            $entryBefore = $before->ledger[$registration] ?? null;
            $entryAfter = $after->ledger[$registration] ?? null;
            $shown = $entryAfter ?? $entryBefore;
            $chainBefore = $entryBefore !== null && $shown['kind'] === 'handler' ? $before->chains[$shown['name']] ?? [] : [];
            $chainAfter = $entryAfter !== null && $shown['kind'] === 'handler' ? $after->chains[$shown['name']] ?? [] : [];
            if ($entryBefore === $entryAfter && $chainBefore === $chainAfter) {
                continue;
            }
            $changes[] = [
                'change' => match (true) {
                    $entryBefore === null => self::ADDED,
                    $entryAfter === null => self::REMOVED,
                    default => self::CHANGED,
                },
                'kind' => $shown['kind'],
                'name' => $shown['name'],
                ...($entryBefore === null ? [] : ['before' => $entryBefore]),
                ...($entryAfter === null ? [] : ['after' => $entryAfter]),
                ...($chainBefore === [] && $chainAfter === [] ? [] : ['chain' => array_map(fn (array $layer): array => ['change' => $layer['change'], 'layer' => $layer['step']], self::changesBetween($chainBefore, $chainAfter))]),
            ];
        }
        usort($changes, fn (array $first, array $second): int => [($first['after'] ?? $first['before'])['module'], $first['kind'], $first['name']] <=> [($second['after'] ?? $second['before'])['module'], $second['kind'], $second['name']]);

        return $changes;
    }

    /**
     * @return array<string, string>
     */
    public function buildChangeIdentities(): array
    {
        $identities = [];
        foreach ($this->build as $change) {
            $identities[$change['kind'] . ':' . $change['name']] = md5(json_encode($change, JSON_THROW_ON_ERROR));
        }

        return $identities;
    }

    /**
     * @param array<string, mixed> $registrations
     */
    public function withoutBuildChangesOf(array $registrations): self
    {
        return new self(scenario: $this->scenario, state: $this->state, before: $this->before, after: $this->after, wiring: $this->wiring, trace: $this->trace, build: array_values(array_filter($this->build, fn (array $change): bool => ! isset($registrations[$change['kind'] . ':' . $change['name']]))), execution: $this->execution);
    }

    /**
     * @return list<array{module: string, entries: list<array<string, mixed>>}>
     */
    public function buildByModule(): array
    {
        $modules = [];
        foreach ($this->build as $change) {
            $module = ($change['after'] ?? $change['before'])['module'];
            $modules[$module] ??= ['module' => $module, 'entries' => []];
            $modules[$module]['entries'][] = $change;
        }

        return array_values($modules);
    }

    public function hasChanges(): bool
    {
        return $this->state !== self::CHANGED || $this->wiring->hasChanges() || $this->hasTraceChanges() || $this->build !== [] || $this->hasExecutionChanges();
    }

    public function hasExecutionChanges(): bool
    {
        return array_filter($this->execution, fn (array $process): bool => $process['change'] !== self::UNCHANGED) !== [];
    }

    public function hasTraceChanges(): bool
    {
        return array_filter($this->trace, fn (array $entry): bool => $entry['change'] !== self::UNCHANGED) !== [];
    }

    public function identity(?string $varyingName = null, ?string $nameItIsExchangedWith = null): string
    {
        $comparison = $this->wiring->toArray();
        $parts = [[$this->state]];
        foreach (['added', 'removed', 'changed'] as $change) {
            $parts[] = $comparison[$change]['nodes'];
            $parts[] = $comparison[$change]['edges'];
        }
        $parts[] = $this->build;
        $parts[] = array_values(array_filter($this->execution, fn (array $process): bool => $process['change'] !== self::UNCHANGED));
        $parts[] = $this->scopedTrace();
        $canonical = [];
        foreach ($parts as $position => $elements) {
            $encoded = array_map(fn (mixed $element): string => self::withoutNames(text: json_encode($element, JSON_THROW_ON_ERROR), varyingName: $varyingName, nameItIsExchangedWith: $nameItIsExchangedWith), $elements);
            if ($position !== array_key_last($parts)) {
                sort($encoded, SORT_STRING);
            }
            $canonical[] = $encoded;
        }

        return md5(json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, int>
     */
    public function namesIn(): array
    {
        preg_match_all('/[A-Za-z][A-Za-z0-9_]*/', json_encode([$this->wiring->toArray(), $this->scopedTrace(), $this->build], JSON_THROW_ON_ERROR), $names);

        return array_count_values(array_map(strtolower(...), $names[0]));
    }

    private static function withoutNames(string $text, ?string $varyingName, ?string $nameItIsExchangedWith): string
    {
        if ($varyingName === null) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/' . preg_quote($varyingName, '/') . ($nameItIsExchangedWith === null ? '' : '|' . preg_quote($nameItIsExchangedWith, '/')) . '/i',
            fn (array $found): string => strtolower($found[0]) === strtolower($varyingName) ? '{name}' : '{other name}',
            $text,
        );
    }

    /**
     * @return list<array{nodes: list<string>, unchangedNeighboursLeftOut: int}>
     */
    public function wiringParts(): array
    {
        return self::fittedTogether(array_values(array_filter($this->connectedParts(), fn (array $part): bool => $part['onThePath'])));
    }

    /**
     * @return array{added: list<FlowNode>, removed: list<FlowNode>}
     */
    public function registeredOffThePath(): array
    {
        $offThePath = [];
        foreach ($this->connectedParts() as $part) {
            if (! $part['onThePath']) {
                $offThePath += array_flip($part['nodes']);
            }
        }
        $named = fn (array $nodes): array => array_values(array_filter($nodes, fn (FlowNode $node): bool => isset($offThePath[$node->id])));

        return ['added' => $named($this->wiring->addedNodes), 'removed' => $named($this->wiring->removedNodes)];
    }

    /**
     * @return list<array{nodes: list<string>, unchangedNeighboursLeftOut: int, onThePath: bool}>
     */
    private function connectedParts(): array
    {
        $combined = $this->wiring->combinedGraph();
        $onTheFlow = [];
        $changedNodes = [];
        foreach ([...$this->wiring->addedNodes, ...$this->wiring->removedNodes, ...array_map(fn (FlowNodeChange $change): FlowNode => $change->after, $this->wiring->changedNodes)] as $node) {
            $changedNodes[$node->id] = true;
        }
        $changedEdges = [...$this->wiring->addedEdges, ...$this->wiring->removedEdges, ...array_map(fn (FlowEdgeChange $change): FlowEdge => $change->after, $this->wiring->changedEdges)];
        $neighbours = [];
        foreach ($combined->edges() as $edge) {
            $neighbours[$edge->from][$edge->to] = true;
            $neighbours[$edge->to][$edge->from] = true;
            if ($edge->kind !== FlowEdgeKind::INTERCEPTED_BY) {
                $onTheFlow[$edge->from][$edge->to] = true;
                $onTheFlow[$edge->to][$edge->from] = true;
            }
        }
        $tracedPlaces = [];
        foreach ($this->trace as $entry) {
            if ($entry['change'] !== self::UNCHANGED) {
                $operationAndPlace = explode(' ', (string) $entry['step']['name'], 2);
                $tracedPlaces[(string) ($entry['step']['destination'] ?? $operationAndPlace[1] ?? '')] = true;
            }
        }
        $cores = $changedNodes;
        $arrowEnds = [];
        foreach ($changedEdges as $edge) {
            $arrowEnds[$edge->from] = $arrowEnds[$edge->to] = true;
            if (! isset($changedNodes[$edge->from]) && ! isset($changedNodes[$edge->to])) {
                $cores[$edge->from] = $cores[$edge->to] = true;
            }
        }
        ksort($cores, SORT_STRING);

        $parts = [];
        $placed = [];
        foreach (array_keys($cores) as $start) {
            if (isset($placed[$start])) {
                continue;
            }
            $connectedPart = [];
            $queue = [$start];
            $placed[$start] = true;
            while ($queue !== []) {
                $current = array_shift($queue);
                $connectedPart[] = $current;
                $adjacent = array_keys($neighbours[$current] ?? []);
                sort($adjacent, SORT_STRING);
                foreach ($adjacent as $next) {
                    if (isset($cores[$next]) && ! isset($placed[$next])) {
                        $placed[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
            $onThePath = false;
            foreach ($connectedPart as $coreNode) {
                $onThePath = $onThePath
                    || ! isset($changedNodes[$coreNode])
                    || array_diff_key(($combined->node($coreNode)->kind === FlowNodeKind::INTERCEPTOR ? $neighbours : $onTheFlow)[$coreNode] ?? [], $changedNodes) !== []
                    || isset($tracedPlaces[(string) ($combined->node($coreNode)->attributes['name'] ?? $combined->node($coreNode)->label)]);
            }
            foreach (array_chunk($connectedPart, self::NODES_PER_PART) as $shownCore) {
                $context = [];
                foreach ($shownCore as $coreNode) {
                    foreach (array_keys($neighbours[$coreNode] ?? []) as $neighbour) {
                        if (! in_array($neighbour, $shownCore, true)) {
                            $context[$neighbour] = [isset($arrowEnds[$neighbour]) ? 0 : 1, $neighbour];
                        }
                    }
                }
                uasort($context, fn (array $first, array $second): int => $first <=> $second);
                $shownContext = array_slice(array_keys($context), 0, self::NODES_PER_PART - count($shownCore));
                $parts[] = ['nodes' => [...$shownCore, ...$shownContext], 'unchangedNeighboursLeftOut' => count(array_diff_key($context, array_flip($shownContext), $changedNodes)), 'onThePath' => $onThePath];
            }
        }

        return $parts;
    }

    /**
     * @param list<array{nodes: list<string>, unchangedNeighboursLeftOut: int, onThePath: bool}> $parts
     * @return list<array{nodes: list<string>, unchangedNeighboursLeftOut: int}>
     */
    private static function fittedTogether(array $parts): array
    {
        $fitted = [];
        foreach ($parts as $part) {
            $last = count($fitted) - 1;
            $together = $last < 0 ? [] : array_values(array_unique([...$fitted[$last]['nodes'], ...$part['nodes']]));
            if ($last >= 0 && count($together) <= self::NODES_PER_PART) {
                $fitted[$last] = ['nodes' => $together, 'unchangedNeighboursLeftOut' => $fitted[$last]['unchangedNeighboursLeftOut'] + $part['unchangedNeighboursLeftOut']];
            } else {
                $fitted[] = $part;
            }
        }

        return array_map(function (array $part): array {
            sort($part['nodes'], SORT_STRING);

            return ['nodes' => $part['nodes'], 'unchangedNeighboursLeftOut' => $part['unchangedNeighboursLeftOut']];
        }, $fitted);
    }

    public function wiringPartOfBothSides(int $part): MessageFlowGraph
    {
        return self::cutTo($this->wiring->combinedGraph(), $this->wiringParts()[$part]['nodes']);
    }

    public function wiringPartBefore(int $part): MessageFlowGraph
    {
        return self::cutTo($this->before->wiring, $this->wiringParts()[$part]['nodes']);
    }

    public function wiringPartAfter(int $part): MessageFlowGraph
    {
        return self::cutTo($this->after->wiring, $this->wiringParts()[$part]['nodes']);
    }

    /**
     * @return list<array{from: string, to: string, label: string, derived: bool}>
     */
    public function messagePath(string $skippedChange): array
    {
        $kept = $this->keptTracePositions();
        $wiring = $skippedChange === self::ADDED ? $this->before->wiring : $this->after->wiring;
        $placeAtDepth = [];
        $path = [];
        foreach ($this->trace as $position => $entry) {
            if ($entry['change'] === $skippedChange) {
                continue;
            }
            $step = $entry['step'];
            [$operation, $subject] = explode(' ', (string) $step['name'], 2) + [1 => ''];
            $from = $placeAtDepth[$step['depth'] - 1] ?? 'caller';
            if (isset($step[self::PART_REPORT]) && isset($kept[$position])) {
                $mark = match ($entry['change']) {
                    self::ADDED => '+ ',
                    self::REMOVED => '- ',
                    default => '',
                };
                $sender = FlowWording::withShortClassNames($step[self::PART_REPORT]['sender']);
                if (isset($placeAtDepth[$step['depth']])) {
                    $path[] = ['from' => $placeAtDepth[$step['depth']], 'to' => $sender, 'label' => $mark . 'ends, its part completion runs (build, no span)', 'derived' => true];
                }
                $path[] = ['from' => $sender, 'to' => 'channel ' . $step[self::PART_REPORT]['channel'], 'label' => $mark . 'sends part report (build, no span)', 'derived' => true];
                $path[] = ['from' => 'channel ' . $step[self::PART_REPORT]['channel'], 'to' => 'channel ' . $subject, 'label' => $mark . 'asynchronous routing sends', 'derived' => false];
                $placeAtDepth[$step['depth']] = 'channel ' . $subject;
                continue;
            }
            [$place, $label] = match (true) {
                $operation === 'route' => [$from, null],
                $operation === 'send' && in_array($subject, ['command', 'event', 'query'], true) => [$subject . ' bus', $subject . ' ' . ($step['destination'] ?? '')],
                $operation === 'send' => ['channel ' . $subject, 'sends'],
                $operation === 'receive' => ['consumer of ' . $subject, 'delivers'],
                isset($step[self::DERIVED]) => [substr(FlowWording::ofStep($step, $wiring), strlen('process ')), 'routed to (wiring, no span)'],
                $operation === 'process' => [substr(FlowWording::ofStep($step, $wiring), strlen('process ')), 'handles'],
                default => [(string) $step['name'], 'runs'],
            };
            if ($operation === 'receive' && ! str_starts_with($from, 'channel ')) {
                $from = 'channel ' . $subject;
            }
            $gathered = isset($step[self::DERIVED]) ? $this->gatheredHandler($step, $entry['change']) : null;
            if ($gathered !== null && isset($kept[$position])) {
                $mark = match ($entry['change']) {
                    self::ADDED => '+ ',
                    self::REMOVED => '- ',
                    default => '',
                };
                $path[] = ['from' => $from, 'to' => 'channel ' . $gathered['inputChannel'], 'label' => $mark . 'routed to (wiring, no span)', 'derived' => true];
                $path[] = ['from' => 'channel ' . $gathered['inputChannel'], 'to' => 'gatherer (Enterprise only)', 'label' => $mark . 'delivers (build, no span)', 'derived' => true];
                $path[] = ['from' => 'gatherer (Enterprise only)', 'to' => $place, 'label' => $mark . 'invokes (build, no span)', 'derived' => true];
                $placeAtDepth[$step['depth']] = $place;
                continue;
            }
            $placeAtDepth[$step['depth']] = $place;
            if ($label !== null && isset($kept[$position])) {
                $path[] = [
                    'from' => $from,
                    'to' => $place,
                    'label' => match ($entry['change']) {
                        self::ADDED => '+ ',
                        self::REMOVED => '- ',
                        default => '',
                    } . $label,
                    'derived' => isset($step[self::DERIVED]),
                ];
            }
        }

        return $path;
    }

    public function runsTheSameSteps(): bool
    {
        if ($this->state !== self::CHANGED || $this->hasTraceChanges() || $this->wiring->hasChanges() || $this->build !== [] || ! $this->hasExecutionChanges()) {
            return false;
        }
        foreach ($this->execution as $process) {
            if (! in_array($process['change'], [self::UNCHANGED, self::CHANGED], true) || array_key_exists('sentByBefore', $process)) {
                return false;
            }
            foreach ($process['steps'] ?? [] as $entry) {
                if (in_array($entry['change'], [self::ADDED, self::REMOVED, self::MOVED], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array{before: string, now: string}
     */
    public function summary(): array
    {
        if ($this->state !== self::CHANGED) {
            return $this->state === self::ADDED
                ? ['before' => 'Before: this scenario was not in the catalogue.', 'now' => 'Now: it is, with ' . count($this->after->trace) . ' traced spans.']
                : ['before' => 'Before: this scenario was in the catalogue, with ' . count($this->before->trace) . ' traced spans.', 'now' => 'Now: it is gone.'];
        }
        if (! $this->hasTraceChanges() && ! $this->wiring->hasChanges() && $this->build === []) {
            $changed = array_values(array_filter($this->execution, fn (array $process): bool => $process['change'] !== self::UNCHANGED && ($process['steps'] ?? null) !== []));
            $now = $this->runsTheSameSteps()
                ? implode('; ', array_map(fn (array $process): string => 'process `' . $process['process'] . '` ' . rtrim(FlowWording::ofChangedProcess($process['steps']), '.'), $changed)) . '.'
                : implode(' ', array_filter([FlowWording::ofSubFlowChanges($this->execution), $changed === [] ? '' : count($changed) . ' process' . (count($changed) === 1 ? ' runs' : 'es run') . ' other steps.']));

            return ['before' => 'Before and now: the wiring, the build and the traced spans are the same.', 'now' => 'Now: ' . $now];
        }
        if (! $this->hasTraceChanges() && ! $this->wiring->hasChanges()) {
            $counts = [];
            foreach ($this->build as $change) {
                $counts[$change['kind'] . ' ' . $change['change']] = ($counts[$change['kind'] . ' ' . $change['change']] ?? 0) + 1;
            }
            $counted = [];
            foreach ($counts as $kindAndChange => $count) {
                [$change, $kind] = [substr($kindAndChange, strrpos($kindAndChange, ' ') + 1), substr($kindAndChange, 0, strrpos($kindAndChange, ' '))];
                $counted[] = $count . ' ' . $kind . ($count === 1 ? ' ' : 's ') . $change;
            }

            return ['before' => 'Before and now: the wiring and the traced spans are the same.', 'now' => 'Now: only the build changed: ' . implode(', ', $counted) . '.'];
        }
        if (! $this->hasTraceChanges()) {
            $names = fn (array $nodes, MessageFlowGraph $wiring): string => implode(', ', array_map(fn (FlowNode $node): string => '`' . FlowWording::ofNode($node, $wiring) . '`', array_slice($nodes, 0, 4))) . (count($nodes) > 4 ? ' and ' . (count($nodes) - 4) . ' more' : '');
            $now = array_filter([
                $this->wiring->addedNodes === [] ? null : 'the wiring also has ' . $names($this->wiring->addedNodes, $this->after->wiring),
                $this->wiring->removedNodes === [] ? null : 'the wiring no longer has ' . $names($this->wiring->removedNodes, $this->before->wiring),
                $this->wiring->changedNodes === [] ? null : count($this->wiring->changedNodes) . ' nodes changed',
                $this->wiring->addedNodes === [] && $this->wiring->removedNodes === [] && $this->wiring->changedNodes === [] ? 'only arrows between existing nodes changed' : null,
            ]);

            return ['before' => 'Before and now: the traced spans are the same.', 'now' => 'Now: ' . implode('; ', $now) . '.'];
        }
        $changedPositions = array_keys(array_filter($this->trace, fn (array $entry): bool => $entry['change'] !== self::UNCHANGED));
        $anchor = null;
        foreach ($this->trace as $position => $entry) {
            if ($position >= $changedPositions[0]) {
                break;
            }
            $anchor = '`' . FlowWording::ofStep($entry['step'], $this->after->wiring) . '`';
        }
        $next = $this->trace[max($changedPositions) + 1]['step'] ?? null;
        $firstChanged = $this->trace[$changedPositions[0]];
        $startsWithASend = $firstChanged['change'] === self::ADDED && str_starts_with((string) $firstChanged['step']['name'], 'send ');
        if ($startsWithASend) {
            foreach ($this->trace as $position => $entry) {
                if ($position >= $changedPositions[0]) {
                    break;
                }
                if ($entry['step']['depth'] === $firstChanged['step']['depth']) {
                    $anchor = '`' . FlowWording::ofStep($entry['step'], $this->after->wiring) . '`';
                }
            }
        }
        $after = $anchor === null ? 'at the start' : 'after ' . (isset($firstChanged['step'][self::PART_REPORT]) ? 'each ' : '') . $anchor;
        $unchangedSide = fn (string $tense): string => match (true) {
            $anchor === null => 'the traced flow ' . ($tense === 'past' ? 'started' : 'starts') . ' with `' . FlowWording::ofStep($next ?? ['name' => 'nothing'], $this->after->wiring) . '`',
            $next === null && $startsWithASend && $tense === 'past' => 'nothing was sent after ' . $anchor,
            $next === null => 'the traced flow ' . ($tense === 'past' ? 'ended' : 'ends') . ' with ' . $anchor,
            default => $anchor . ($tense === 'past' ? ' was' : ' is') . ' followed directly by `' . FlowWording::ofStep($next, $this->after->wiring) . '`',
        };
        $removed = $this->phrasesOf(self::REMOVED);
        $added = $this->phrasesOf(self::ADDED);

        return [
            'before' => 'Before: ' . ($removed === [] ? $unchangedSide('past') : $after . ', ' . implode('; ', $removed)) . '.',
            'now' => 'Now: ' . ($added === [] ? $unchangedSide('present') : $after . ', ' . implode('; ', $added)) . '.' . $this->whatRanAlso(),
        ];
    }

    /**
     * @return list<string>
     */
    private function phrasesOf(string $change): array
    {
        $wiring = $change === self::ADDED ? $this->after->wiring : $this->before->wiring;
        $skippedChange = $change === self::ADDED ? self::REMOVED : self::ADDED;
        $stepAtDepth = [];
        $phrases = [];
        foreach ($this->trace as $entry) {
            if ($entry['change'] === $skippedChange) {
                continue;
            }
            $step = $entry['step'];
            $stepAtDepth[$step['depth']] = $step;
            if ($entry['change'] !== $change) {
                continue;
            }
            [$operation, $subject] = explode(' ', (string) $step['name'], 2) + [1 => ''];
            $sender = $stepAtDepth[$step['depth'] - 1] ?? null;
            $actor = match (true) {
                $sender === null => 'the caller',
                str_starts_with((string) $sender['name'], 'receive ') => 'the consumer of `' . substr((string) $sender['name'], strlen('receive ')) . '`',
                default => '`' . FlowWording::ofStep($sender, $wiring) . '`',
            };
            $phrases[] = match (true) {
                isset($step[self::PART_REPORT]) => ($step[self::PART_REPORT]['chosenFor'] === '' ? '' : 'the Enterprise part completion, ')
                    . '`' . FlowWording::withShortClassNames($step[self::PART_REPORT]['sender']) . '`' . ($step[self::PART_REPORT]['chosenFor'] === '' ? '' : ',')
                    . " sends a part report to `{$step[self::PART_REPORT]['channel']}`, which goes on to `{$subject}`",
                isset($step[self::DERIVED]) => 'it is routed to ' . ($this->isGathered($step, $change) ? 'the gathered handler ' : '') . '`' . substr(FlowWording::ofStep($step, $wiring), strlen('process ')) . '` (read from the wiring, not recorded)',
                $operation === 'send' && in_array($subject, ['command', 'event', 'query'], true) => "{$actor} sends {$subject} `" . ($step['destination'] ?? '') . '`',
                $operation === 'send' => "{$actor} sends a message to `{$subject}`",
                $operation === 'receive' => "it is received from `{$subject}`",
                $operation === 'route' => 'it is routed to `' . ($step['destination'] ?? $subject) . '`',
                $operation === 'process' => '`' . substr(FlowWording::ofStep($step, $wiring), strlen('process ')) . '` handles it',
                default => '`' . $step['name'] . '` runs',
            };
        }

        return self::withoutRepeats($phrases);
    }

    /**
     * @param list<string> $phrases
     * @return list<string>
     */
    private static function withoutRepeats(array $phrases): array
    {
        $count = count($phrases);
        for ($period = 1; $period < $count; $period++) {
            if ($count % $period === 0 && array_merge(...array_fill(0, intdiv($count, $period), array_slice($phrases, 0, $period))) === $phrases) {
                $once = array_slice($phrases, 0, $period);
                $once[$period - 1] .= ', ' . intdiv($count, $period) . ' times';

                return $once;
            }
        }

        return $phrases;
    }

    private function whatRanAlso(): string
    {
        $facts = [];
        foreach ($this->execution as $process) {
            foreach ($process['steps'] ?? [] as $entry) {
                if ($entry['change'] === self::ADDED && preg_match('/^open gathering group of (\S+) parts$/', trim((string) ($entry['step'] ?? '')), $group) === 1) {
                    $facts['group'] = 'opens a gathering group of ' . $group[1] . ' parts';
                }
            }
        }
        $gatherers = count(array_filter($this->execution, fn (array $process): bool => $process['change'] === self::ADDED && str_contains($process['process'], 'GatheringMessageHandler::handle')));
        if ($gatherers > 0) {
            $facts['gatherers'] = $gatherers . ' gatherer run' . ($gatherers === 1 ? ' takes' : 's take') . ' the part reports in';
        }

        return $facts === [] ? '' : ' What ran also: it ' . implode(', and ', $facts) . '.';
    }

    /**
     * @param array<string, mixed> $step
     */
    private function isGathered(array $step, string $change): bool
    {
        return $this->gatheredHandler($step, $change) !== null;
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, scalar>|null
     */
    private function gatheredHandler(array $step, string $change): ?array
    {
        foreach (($change === self::REMOVED ? $this->before : $this->after)->ledger as $entry) {
            if (($entry['builder'] ?? null) === 'gatherer' && isset($entry['inputChannel']) && 'process ' . FlowWording::withShortClassNames((string) ($entry['target'] ?? '')) === $step['name']) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function partReportChannels(): array
    {
        $channels = [];
        foreach ($this->trace as $entry) {
            if (isset($entry['step'][self::PART_REPORT])) {
                $channels[$entry['step'][self::PART_REPORT]['channel']] = $entry['step'][self::PART_REPORT]['sender'];
            }
        }

        return $channels;
    }

    /**
     * @return array<int, true>
     */
    private function keptTracePositions(): array
    {
        if (count(array_filter($this->trace, fn (array $entry): bool => $entry['change'] === self::UNCHANGED)) <= self::UNCHANGED_STEPS_KEPT_WHOLE) {
            return $this->hasTraceChanges() ? array_fill(0, count($this->trace), true) : [];
        }
        $kept = [];
        foreach ($this->trace as $position => $entry) {
            if ($entry['change'] !== self::UNCHANGED) {
                $kept[$position - 1] = $kept[$position] = $kept[$position + 1] = true;
            }
        }

        return $kept;
    }

    /**
     * @return list<array{change: string, step?: array<string, mixed>, steps?: int}>
     */
    public function scopedTrace(): array
    {
        $kept = $this->keptTracePositions();
        $scoped = [];
        $leftOut = 0;
        foreach ($this->trace as $position => $entry) {
            if (! isset($kept[$position])) {
                $leftOut++;
                continue;
            }
            if ($leftOut > 0) {
                $scoped[] = ['change' => self::LEFT_OUT, 'steps' => $leftOut];
                $leftOut = 0;
            }
            $scoped[] = $entry;
        }
        if ($leftOut > 0 && $scoped !== []) {
            $scoped[] = ['change' => self::LEFT_OUT, 'steps' => $leftOut];
        }

        return $scoped;
    }

    /**
     * @return array{state: string, summary: array{before: string, now: string}, wiring: array<string, mixed>, wiringParts: list<array{nodes: list<string>, unchangedNeighboursLeftOut: int}>, registeredOffThePath: array{added: list<string>, removed: list<string>}, trace: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'summary' => $this->summary(),
            'wiring' => $this->wiring->toArray(),
            'wiringParts' => $this->wiringParts(),
            'registeredOffThePath' => array_map(fn (array $nodes): array => array_map(fn (FlowNode $node): string => $node->id, $nodes), $this->registeredOffThePath()),
            'build' => $this->buildByModule(),
            'trace' => $this->scopedTrace(),
            'execution' => $this->execution,
        ];
    }

    /**
     * @param list<array{process: string, sentBy?: array{processNumber: int, stepNumber: int}, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}> $before
     * @param list<array{process: string, sentBy?: array{processNumber: int, stepNumber: int}, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}> $after
     * @return list<array{process: string, change: string, sentBy?: string, sentAt?: int, sentByBefore?: ?string, steps?: list<array{change: string, step?: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool}>}>
     */
    private static function executionChangesBetween(array $before, array $after): array
    {
        $beforeSenders = self::sendersOf($before);
        $afterSenders = self::sendersOf($after);
        $beforeSteps = array_map(fn (array $process): ?int => $process['sentBy']['stepNumber'] ?? null, $before);
        $afterSteps = array_map(fn (array $process): ?int => $process['sentBy']['stepNumber'] ?? null, $after);
        $before = self::withProcessesNamedByTitle($before);
        $after = self::withProcessesNamedByTitle($after);
        $processes = [];
        $beforePosition = $afterPosition = 0;
        foreach (self::changesBetween(array_column($before, 'process'), array_column($after, 'process')) as $entry) {
            [$process, $senders, $sentAt] = match ($entry['change']) {
                self::UNCHANGED => [[$before[$beforePosition], $after[$afterPosition]], [$beforeSenders[$beforePosition++], $afterSenders[$afterPosition]], $afterSteps[$afterPosition++]],
                self::REMOVED => [[$before[$beforePosition], null], [$beforeSenders[$beforePosition], null], $beforeSteps[$beforePosition++]],
                default => [[null, $after[$afterPosition]], [null, $afterSenders[$afterPosition]], $afterSteps[$afterPosition++]],
            };
            $sentBy = $process[1] === null ? $senders[0] : $senders[1];
            $attachedElsewhere = $process[0] !== null && $process[1] !== null && $senders[0] !== $senders[1];
            $processes[] = [
                ...match (true) {
                    $process[0] === null => ['process' => $entry['step'], 'change' => self::ADDED, 'steps' => array_map(fn (array $step): array => ['change' => self::ADDED, 'step' => str_repeat('  ', $step['depth']) . $step['label'], 'hints' => self::shownInLinesOf($step['hints'])], $process[1]['steps'])],
                    $process[1] === null => ['process' => $entry['step'], 'change' => self::REMOVED, 'steps' => array_map(fn (array $step): array => ['change' => self::REMOVED, 'step' => str_repeat('  ', $step['depth']) . $step['label'], 'hints' => self::shownInLinesOf($step['hints'])], $process[0]['steps'])],
                    $process[0]['steps'] === $process[1]['steps'] => ['process' => $entry['step'], 'change' => $attachedElsewhere ? self::CHANGED : self::UNCHANGED, ...($attachedElsewhere ? ['steps' => []] : [])],
                    default => ['process' => $entry['step'], 'change' => self::CHANGED, 'steps' => self::withUnchangedNeighboursOnly(FlowExecutionChange::between($process[0]['steps'], $process[1]['steps']))],
                },
                ...($sentBy === null ? [] : ['sentBy' => $sentBy, 'sentAt' => (int) $sentAt]),
                ...($attachedElsewhere ? ['sentByBefore' => $senders[0]] : []),
            ];
        }

        return $processes;
    }

    /**
     * @param list<array{process: string, sentBy?: array{processNumber: int, stepNumber: int}, steps: list<array<string, mixed>>}> $processes
     * @return list<?string>
     */
    private static function sendersOf(array $processes): array
    {
        return array_map(
            fn (int $position): ?string => RecordedExecution::isSubFlow($processes, $position) ? $processes[$processes[$position]['sentBy']['processNumber'] - 1]['process'] : null,
            array_keys($processes),
        );
    }

    /**
     * @param list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}> $processes
     * @return list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}>
     */
    private static function withProcessesNamedByTitle(array $processes): array
    {
        $titles = array_map(fn (string $title): string => '`' . $title . '`', array_column($processes, 'process'));
        $named = fn (string $text): string => (string) preg_replace_callback('/\bprocess (\d+)\b/', fn (array $number): string => 'process ' . ($titles[(int) $number[1] - 1] ?? $number[1]), $text);

        return array_map(fn (array $process): array => [
            'process' => $process['process'],
            'steps' => array_map(fn (array $step): array => [
                ...$step,
                'hints' => array_values(array_map(
                    fn (array $hint): array => in_array($hint['key'], [ExecutionHint::SENT_BY, ExecutionHint::TAKEN_BY, ExecutionHint::WORK_IN, ExecutionHint::STARTED_IN, ExecutionHint::STARTS], true) ? [...$hint, 'value' => $named($hint['value'])] : $hint,
                    $step['hints'],
                )),
            ], $process['steps']),
        ], $processes);
    }

    /**
     * @param list<array{key: string, value: string, shownInLines: bool}> $hints
     * @return list<array{key: string, value: string, shownInLines: bool}>
     */
    private static function shownInLinesOf(array $hints): array
    {
        return array_values(array_filter($hints, fn (array $hint): bool => $hint['shownInLines']));
    }

    /**
     * @param list<array{change: string, step: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool}> $steps
     * @return list<array{change: string, step?: string, steps?: int, times?: int, movement?: string, parent?: string, changedInside?: bool}>
     */
    private static function withUnchangedNeighboursOnly(array $steps): array
    {
        $kept = [];
        foreach ($steps as $position => $entry) {
            if ($entry['change'] !== self::UNCHANGED) {
                foreach (range($position - self::UNCHANGED_NEIGHBOURS_OF_AN_EXECUTED_STEP, $position + self::UNCHANGED_NEIGHBOURS_OF_AN_EXECUTED_STEP) as $neighbour) {
                    $kept[$neighbour] = true;
                }
            }
        }
        $scoped = [];
        $leftOut = 0;
        foreach ($steps as $position => $entry) {
            if (! isset($kept[$position])) {
                $leftOut++;
                continue;
            }
            if ($leftOut > 0) {
                $scoped[] = ['change' => self::LEFT_OUT, 'steps' => $leftOut];
                $leftOut = 0;
            }
            $scoped[] = $entry;
        }
        if ($leftOut > 0) {
            $scoped[] = ['change' => self::LEFT_OUT, 'steps' => $leftOut];
        }

        return $scoped;
    }

    /**
     * @param list<array{change: string, step: array<string, mixed>}> $trace
     * @return list<array{change: string, step: array<string, mixed>}>
     */
    private static function withContinuationsFromWiring(array $trace, MessageFlowGraph $before, MessageFlowGraph $after): array
    {
        $continued = [];
        foreach ($trace as $position => $entry) {
            $continued[] = $entry;
            $step = $entry['step'];
            $hasStepBelow = ($trace[$position + 1]['step']['depth'] ?? 0) > $step['depth'];
            if ($entry['change'] === self::UNCHANGED || $hasStepBelow || ! str_starts_with((string) $step['name'], 'receive ')) {
                continue;
            }
            $wiring = $entry['change'] === self::ADDED ? $after : $before;
            $other = $entry['change'] === self::ADDED ? $before : $after;
            foreach (self::handlersReachedFrom(channelId: 'channel:' . substr((string) $step['name'], strlen('receive ')), wiring: $wiring, other: $other) as $handler) {
                $continued[] = ['change' => $entry['change'], 'step' => [
                    'depth' => $step['depth'] + 1,
                    'name' => 'process ' . FlowWording::ofNode($handler, $wiring),
                    'kind' => 'Wiring',
                    'status' => 'Ok',
                    self::DERIVED => true,
                ]];
            }
        }

        return $continued;
    }

    /**
     * @param list<array{change: string, step: array<string, mixed>}> $trace
     * @return list<array{change: string, step: array<string, mixed>}>
     */
    private static function withPartReports(array $trace, FlowSnapshot $before, FlowSnapshot $after): array
    {
        $stepAtDepth = [];
        foreach ($trace as $position => $entry) {
            $step = $entry['step'];
            $sentWhileConsuming = str_starts_with((string) ($stepAtDepth[$step['depth'] - 1]['name'] ?? ''), 'receive ');
            $stepAtDepth[$step['depth']] = $step;
            if ($entry['change'] === self::UNCHANGED || ! $sentWhileConsuming || ! str_starts_with((string) $step['name'], 'send ') || isset($step['destination'])) {
                continue;
            }
            $partReport = self::partReportSentThrough(substr((string) $step['name'], strlen('send ')), $entry['change'] === self::ADDED ? $after : $before);
            if ($partReport !== null) {
                $trace[$position]['step'][self::PART_REPORT] = $partReport;
            }
        }

        return $trace;
    }

    /**
     * @return array{sender: string, chosenFor: string, channel: string, gatherer: string}|null
     */
    private static function partReportSentThrough(string $channel, FlowSnapshot $snapshot): ?array
    {
        $gatherChannels = [];
        foreach ($snapshot->ledger as $routing) {
            if ($routing['kind'] !== 'handler' || ($routing['outputChannel'] ?? null) !== $channel) {
                continue;
            }
            foreach ($snapshot->ledger as $gatherer) {
                if (($gatherer['builder'] ?? null) === 'gatherer' && in_array($gatherer['inputChannel'] ?? null, [$routing['inputChannel'], $routing['inputChannel'] . '.execute'], true)) {
                    $gatherChannels[] = ['channel' => (string) $routing['inputChannel'], 'gatherer' => (string) ($gatherer['target'] ?? $gatherer['name'])];
                }
            }
        }
        if (count($gatherChannels) !== 1) {
            return null;
        }
        $sender = ['sender' => 'the part completion', 'chosenFor' => ''];
        foreach ($snapshot->ledger as $service) {
            if ($service['kind'] === 'service' && isset($service['enterprise']) && str_ends_with((string) $service['name'], '\\PartCompletion')) {
                $sender = ['sender' => (string) $service['enterprise'], 'chosenFor' => (string) $service['name']];
            }
        }

        return [...$sender, ...$gatherChannels[0]];
    }

    /**
     * @return list<FlowNode>
     */
    private static function handlersReachedFrom(string $channelId, MessageFlowGraph $wiring, MessageFlowGraph $other): array
    {
        if (! $wiring->hasNode($channelId)) {
            return [];
        }
        $reached = [];
        $visited = [$channelId => true];
        $queue = [[$channelId, true]];
        while ($queue !== []) {
            [$current, $mayPassHandlers] = array_shift($queue);
            foreach ($wiring->edgesFrom($current) as $edge) {
                if (! in_array($edge->kind, [FlowEdgeKind::INPUT, FlowEdgeKind::ROUTES_TO, FlowEdgeKind::OUTPUT], true) || isset($visited[$edge->to])) {
                    continue;
                }
                $visited[$edge->to] = true;
                $next = $wiring->node($edge->to);
                if ($next->kind === FlowNodeKind::CHANNEL) {
                    $queue[] = [$next->id, $mayPassHandlers];
                } elseif (! $other->hasNode($next->id)) {
                    $reached[] = $next;
                } elseif ($mayPassHandlers && $current === $channelId) {
                    $queue[] = [$next->id, false];
                }
            }
        }

        return $reached;
    }

    /**
     * @param list<string> $nodeIds
     */
    private static function cutTo(MessageFlowGraph $graph, array $nodeIds): MessageFlowGraph
    {
        $shown = array_flip($nodeIds);

        return MessageFlowGraph::create(
            array_values(array_filter($graph->nodes(), fn (FlowNode $node): bool => isset($shown[$node->id]))),
            array_values(array_filter($graph->edges(), fn (FlowEdge $edge): bool => isset($shown[$edge->from], $shown[$edge->to]))),
        );
    }

    /**
     * @param list<array<string, mixed>|string> $before
     * @param list<array<string, mixed>|string> $after
     * @return list<array{change: string, step: array<string, mixed>|string}>
     */
    private static function changesBetween(array $before, array $after): array
    {
        $same = fn (int $beforePosition, int $afterPosition): bool => $before[$beforePosition] === $after[$afterPosition];
        $common = array_fill(0, count($before) + 1, array_fill(0, count($after) + 1, 0));
        for ($beforePosition = count($before) - 1; $beforePosition >= 0; $beforePosition--) {
            for ($afterPosition = count($after) - 1; $afterPosition >= 0; $afterPosition--) {
                $common[$beforePosition][$afterPosition] = $same($beforePosition, $afterPosition)
                    ? $common[$beforePosition + 1][$afterPosition + 1] + 1
                    : max($common[$beforePosition + 1][$afterPosition], $common[$beforePosition][$afterPosition + 1]);
            }
        }
        $changes = [];
        $beforePosition = $afterPosition = 0;
        while ($beforePosition < count($before) || $afterPosition < count($after)) {
            if ($beforePosition < count($before) && $afterPosition < count($after) && $same($beforePosition, $afterPosition)) {
                $changes[] = ['change' => self::UNCHANGED, 'step' => $after[$afterPosition]];
                $beforePosition++;
                $afterPosition++;
            } elseif ($beforePosition < count($before) && ($afterPosition === count($after) || $common[$beforePosition + 1][$afterPosition] >= $common[$beforePosition][$afterPosition + 1])) {
                $changes[] = ['change' => self::REMOVED, 'step' => $before[$beforePosition++]];
            } else {
                $changes[] = ['change' => self::ADDED, 'step' => $after[$afterPosition++]];
            }
        }

        return $changes;
    }
}
