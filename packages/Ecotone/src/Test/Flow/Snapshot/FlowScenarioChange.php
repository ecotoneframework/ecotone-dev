<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Messaging\Flow\FlowComparison;
use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeChange;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeChange;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;

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
    private const UNCHANGED_STEPS_KEPT_WHOLE = 8;
    private const DERIVED = 'derivedFromWiring';

    /**
     * @param list<array{change: string, step: array<string, mixed>}> $trace
     * @param list<array{change: string, kind: string, name: string, before?: array<string, scalar>, after?: array<string, scalar>, chain?: list<array{change: string, layer: string}>}> $build
     */
    private function __construct(
        public readonly string $scenario,
        public readonly string $state,
        public readonly FlowSnapshot $before,
        public readonly FlowSnapshot $after,
        public readonly FlowComparison $wiring,
        public readonly array $trace,
        public readonly array $build,
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
            trace: self::withContinuationsFromWiring(self::changesBetween($before->trace, $after->trace), $before->wiring, $after->wiring),
            build: $state === self::CHANGED ? self::buildChangesBetween($before, $after) : [],
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
     * @return array<string, string> kind and name of a changed registration => what identifies the change
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
     * @param array<string, mixed> $registrations kind and name of a registration => anything
     */
    public function withoutBuildChangesOf(array $registrations): self
    {
        return new self($this->scenario, $this->state, $this->before, $this->after, $this->wiring, $this->trace, array_values(array_filter($this->build, fn (array $change): bool => ! isset($registrations[$change['kind'] . ':' . $change['name']]))));
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
        return $this->state !== self::CHANGED || $this->wiring->hasChanges() || $this->hasTraceChanges() || $this->build !== [];
    }

    public function hasTraceChanges(): bool
    {
        return array_filter($this->trace, fn (array $entry): bool => $entry['change'] !== self::UNCHANGED) !== [];
    }

    public function identity(?string $varyingName = null): string
    {
        $comparison = $this->wiring->toArray();
        $parts = [[$this->state]];
        foreach (['added', 'removed', 'changed'] as $change) {
            $parts[] = $comparison[$change]['nodes'];
            $parts[] = $comparison[$change]['edges'];
        }
        $parts[] = $this->build;
        $parts[] = $this->scopedTrace();
        $canonical = [];
        foreach ($parts as $position => $elements) {
            $encoded = array_map(fn (mixed $element): string => self::withoutName(json_encode($element, JSON_THROW_ON_ERROR), $varyingName), $elements);
            if ($position < array_key_last($parts) - 1) {
                sort($encoded, SORT_STRING);
            }
            $canonical[] = $encoded;
        }

        return md5(json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string>
     */
    public function namesIn(): array
    {
        preg_match_all('/[A-Za-z][A-Za-z0-9]*/', json_encode([$this->wiring->toArray(), $this->scopedTrace(), $this->build], JSON_THROW_ON_ERROR), $names);

        return array_values(array_unique(array_map(strtolower(...), $names[0])));
    }

    private static function withoutName(string $text, ?string $varyingName): string
    {
        return $varyingName === null ? $text : str_ireplace($varyingName, '{name}', $text);
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

    /**
     * @return array{before: string, now: string}
     */
    public function summary(): array
    {
        if ($this->state !== self::CHANGED) {
            return $this->state === self::ADDED
                ? ['before' => 'Before: this scenario was not in the catalogue.', 'now' => 'Now: it is, with ' . count($this->after->trace) . ' traced steps.']
                : ['before' => 'Before: this scenario was in the catalogue, with ' . count($this->before->trace) . ' traced steps.', 'now' => 'Now: it is gone.'];
        }
        if (! $this->hasTraceChanges()) {
            $names = fn (array $nodes, MessageFlowGraph $wiring): string => implode(', ', array_map(fn (FlowNode $node): string => '`' . FlowWording::ofNode($node, $wiring) . '`', array_slice($nodes, 0, 4))) . (count($nodes) > 4 ? ' and ' . (count($nodes) - 4) . ' more' : '');
            $now = array_filter([
                $this->wiring->addedNodes === [] ? null : 'the wiring also has ' . $names($this->wiring->addedNodes, $this->after->wiring),
                $this->wiring->removedNodes === [] ? null : 'the wiring no longer has ' . $names($this->wiring->removedNodes, $this->before->wiring),
                $this->wiring->changedNodes === [] ? null : count($this->wiring->changedNodes) . ' nodes changed',
                $this->wiring->addedNodes === [] && $this->wiring->removedNodes === [] && $this->wiring->changedNodes === [] ? 'only arrows between existing nodes changed' : null,
            ]);

            return ['before' => 'Before and now: the traced steps are the same.', 'now' => 'Now: ' . implode('; ', $now) . '.'];
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
        $after = $anchor === null ? 'at the start' : 'after ' . $anchor;
        $unchangedSide = fn (string $tense): string => match (true) {
            $anchor === null => 'the traced flow ' . ($tense === 'past' ? 'started' : 'starts') . ' with `' . FlowWording::ofStep($next ?? ['name' => 'nothing'], $this->after->wiring) . '`',
            $next === null => 'the traced flow ' . ($tense === 'past' ? 'ended' : 'ends') . ' with ' . $anchor,
            default => $anchor . ($tense === 'past' ? ' was' : ' is') . ' followed directly by `' . FlowWording::ofStep($next, $this->after->wiring) . '`',
        };
        $removed = $this->phrasesOf(self::REMOVED);
        $added = $this->phrasesOf(self::ADDED);

        return [
            'before' => 'Before: ' . ($removed === [] ? $unchangedSide('past') : $after . ', ' . implode('; ', $removed)) . '.',
            'now' => 'Now: ' . ($added === [] ? $unchangedSide('present') : $after . ', ' . implode('; ', $added)) . '.',
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
                isset($step[self::DERIVED]) => 'it is routed to `' . substr(FlowWording::ofStep($step, $wiring), strlen('process ')) . '` (from the wiring: no span is recorded for that handler)',
                $operation === 'send' && in_array($subject, ['command', 'event', 'query'], true) => "{$actor} sends {$subject} `" . ($step['destination'] ?? '') . '`',
                $operation === 'send' => "{$actor} sends a message to `{$subject}`",
                $operation === 'receive' => "it is received from `{$subject}`",
                $operation === 'route' => 'it is routed to `' . ($step['destination'] ?? $subject) . '`',
                $operation === 'process' => '`' . substr(FlowWording::ofStep($step, $wiring), strlen('process ')) . '` handles it',
                default => '`' . $step['name'] . '` runs',
            };
        }

        return $phrases;
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
        ];
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
            foreach (self::handlersReachedFrom('channel:' . substr((string) $step['name'], strlen('receive ')), $wiring, $other) as $handler) {
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
