<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Messaging\Flow\FlowComparison;
use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeChange;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeChange;
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

    /**
     * @param list<array{change: string, step: array<string, mixed>}> $trace
     */
    private function __construct(
        public readonly string $scenario,
        public readonly string $state,
        public readonly FlowSnapshot $before,
        public readonly FlowSnapshot $after,
        public readonly FlowComparison $wiring,
        public readonly array $trace,
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
            trace: self::changesBetween($before->trace, $after->trace),
        );
    }

    public function hasChanges(): bool
    {
        return $this->state !== self::CHANGED || $this->wiring->hasChanges() || $this->hasTraceChanges();
    }

    public function hasTraceChanges(): bool
    {
        return array_filter($this->trace, fn (array $entry): bool => $entry['change'] !== self::UNCHANGED) !== [];
    }

    public function identity(): string
    {
        return md5(json_encode([$this->state, $this->wiring->toArray(), $this->scopedTrace()], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{nodes: list<string>, unchangedNeighboursLeftOut: int}>
     */
    public function wiringParts(): array
    {
        $combined = $this->wiring->combinedGraph();
        $changedNodes = [];
        foreach ([...$this->wiring->addedNodes, ...$this->wiring->removedNodes, ...array_map(fn (FlowNodeChange $change): FlowNode => $change->after, $this->wiring->changedNodes)] as $node) {
            $changedNodes[$node->id] = true;
        }
        $changedEdges = [...$this->wiring->addedEdges, ...$this->wiring->removedEdges, ...array_map(fn (FlowEdgeChange $change): FlowEdge => $change->after, $this->wiring->changedEdges)];
        $neighbours = [];
        foreach ($combined->edges() as $edge) {
            $neighbours[$edge->from][$edge->to] = true;
            $neighbours[$edge->to][$edge->from] = true;
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
                $parts[] = ['nodes' => [...$shownCore, ...$shownContext], 'unchangedNeighboursLeftOut' => count(array_diff_key($context, array_flip($shownContext), $changedNodes))];
            }
        }

        return self::fittedTogether($parts);
    }

    /**
     * @param list<array{nodes: list<string>, unchangedNeighboursLeftOut: int}> $parts
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

            return $part;
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
     * @return list<array{from: string, to: string, label: string}>
     */
    public function messagePath(string $skippedChange): array
    {
        $kept = $this->keptTracePositions();
        $placeAtDepth = [];
        $path = [];
        foreach ($this->trace as $position => $entry) {
            if ($entry['change'] === $skippedChange) {
                continue;
            }
            $step = $entry['step'];
            $operationAndPlace = explode(' ', (string) $step['name'], 2);
            $placeAtDepth[$step['depth']] = (string) ($step['destination'] ?? $operationAndPlace[1] ?? $operationAndPlace[0]);
            if (isset($kept[$position])) {
                $path[] = [
                    'from' => $placeAtDepth[$step['depth'] - 1] ?? 'Caller',
                    'to' => $placeAtDepth[$step['depth']],
                    'label' => match ($entry['change']) {
                        self::ADDED => '+ ',
                        self::REMOVED => '- ',
                        default => '',
                    } . $step['name'],
                ];
            }
        }

        return $path;
    }

    /**
     * @return array<int, true>
     */
    private function keptTracePositions(): array
    {
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
     * @return array{state: string, wiring: array<string, mixed>, wiringParts: list<array{nodes: list<string>, unchangedNeighboursLeftOut: int}>, trace: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['state' => $this->state, 'wiring' => $this->wiring->toArray(), 'wiringParts' => $this->wiringParts(), 'trace' => $this->scopedTrace()];
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
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     * @return list<array{change: string, step: array<string, mixed>}>
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
