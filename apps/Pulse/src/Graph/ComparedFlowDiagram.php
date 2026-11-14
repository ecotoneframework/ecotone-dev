<?php

declare(strict_types=1);

namespace App\Graph;

use Ecotone\Api\Messaging\Flow\FlowChange;
use Ecotone\Api\Messaging\Flow\FlowComparison;
use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeChange;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeChange;

final readonly class ComparedFlowDiagram
{
    private const GROUP = 's0';

    /**
     * @param list<array{change: string, kind: string, label: string, title: string, show: ?string, differences: list<string>}> $changes
     */
    private function __construct(public Diagram $diagram, public array $changes, public int $added, public int $removed, public int $changed)
    {
    }

    public static function of(string $service, FlowComparison $comparison): self
    {
        $graph = $comparison->combinedGraph();
        $ids = [];
        $nodes = [];
        foreach ($graph->nodes() as $index => $node) {
            $ids[$node->id] = sprintf('%sn%d', self::GROUP, $index);
            $nodes[] = new DiagramNode(
                id: $ids[$node->id],
                group: self::GROUP,
                kind: $node->kind->value,
                label: ShortLabel::of($node->label),
                title: $node->label,
                change: self::markOf($comparison->changeOfNode($node->id)),
            );
        }
        $edges = [];
        foreach ($graph->edges() as $edge) {
            [$arrow, $label] = StitchedFlowGraph::arrow($edge, $graph->node($edge->to));
            $drawn = new DiagramEdge($ids[$edge->from], $ids[$edge->to], $arrow, $label, change: self::markOf($comparison->changeOfEdge($edge)));
            $edges[$drawn->key()] = isset($edges[$drawn->key()]) ? $edges[$drawn->key()]->orChanged($drawn->change) : $drawn;
        }

        $changes = [
            ...array_map(fn (FlowNode $node): array => self::nodeChange('added', $node, $ids), $comparison->addedNodes),
            ...array_map(fn (FlowEdge $edge): array => self::edgeChange('added', $edge, $graph->node($edge->from), $graph->node($edge->to), $ids), $comparison->addedEdges),
            ...array_map(fn (FlowNode $node): array => self::nodeChange('removed', $node, $ids), $comparison->removedNodes),
            ...array_map(fn (FlowEdge $edge): array => self::edgeChange('removed', $edge, $graph->node($edge->from), $graph->node($edge->to), $ids), $comparison->removedEdges),
            ...array_map(fn (FlowNodeChange $change): array => self::nodeChange('changed', $change->after, $ids, self::differences(
                ['kind' => $change->before->kind->value, 'label' => $change->before->label, ...$change->before->attributes],
                ['kind' => $change->after->kind->value, 'label' => $change->after->label, ...$change->after->attributes],
            )), $comparison->changedNodes),
            ...array_map(fn (FlowEdgeChange $change): array => self::edgeChange('changed', $change->after, $graph->node($change->after->from), $graph->node($change->after->to), $ids, self::differences($change->before->attributes, $change->after->attributes)), $comparison->changedEdges),
        ];

        return new self(
            new Diagram([['id' => self::GROUP, 'title' => $service]], $nodes, array_values($edges)),
            $changes,
            count($comparison->addedNodes) + count($comparison->addedEdges),
            count($comparison->removedNodes) + count($comparison->removedEdges),
            count($comparison->changedNodes) + count($comparison->changedEdges),
        );
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }

    private static function markOf(FlowChange $change): ?string
    {
        return $change === FlowChange::UNCHANGED ? null : $change->value;
    }

    /**
     * @param array<string, string> $ids
     * @param list<string> $differences
     * @return array{change: string, kind: string, label: string, title: string, show: ?string, differences: list<string>}
     */
    private static function nodeChange(string $change, FlowNode $node, array $ids, array $differences = []): array
    {
        return ['change' => $change, 'kind' => DiagramNode::kindLabelOf($node->kind->value), 'label' => ShortLabel::of($node->label), 'title' => $node->label, 'show' => $ids[$node->id] ?? null, 'differences' => $differences];
    }

    /**
     * @param array<string, string> $ids
     * @param list<string> $differences
     * @return array{change: string, kind: string, label: string, title: string, show: ?string, differences: list<string>}
     */
    private static function edgeChange(string $change, FlowEdge $edge, FlowNode $from, FlowNode $to, array $ids, array $differences = []): array
    {
        $label = StitchedFlowGraph::arrow($edge, $to)[1];

        return [
            'change' => $change,
            'kind' => 'Arrow',
            'label' => ShortLabel::of($from->label) . ' → ' . ShortLabel::of($to->label) . ($label === '' ? '' : ' (' . $label . ')'),
            'title' => $from->label . ' → ' . $to->label,
            'show' => $ids[$edge->to] ?? null,
            'differences' => $differences,
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string>
     */
    private static function differences(array $before, array $after): array
    {
        $names = array_keys($before + $after);
        sort($names, SORT_STRING);
        $differences = [];
        foreach ($names as $name) {
            $earlier = json_encode($before[$name] ?? null, JSON_UNESCAPED_SLASHES);
            $later = json_encode($after[$name] ?? null, JSON_UNESCAPED_SLASHES);
            if ($earlier !== $later) {
                $differences[] = sprintf('%s: %s → %s', $name, $earlier, $later);
            }
        }

        return $differences;
    }
}
