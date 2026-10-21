<?php

declare(strict_types=1);

namespace App\Graph;

use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;

final class StitchedFlowGraph
{
    /**
     * @param list<ServiceFlow> $flows
     * @param list<string> $shownServices
     */
    public static function toMermaid(array $flows, array $shownServices, ?TraceHighlight $highlight = null): string
    {
        $shown = array_values(array_filter($flows, fn (ServiceFlow $flow) => in_array($flow->name, $shownServices, true)));
        $ids = [];
        $lines = ['flowchart LR'];
        foreach ($shown as $serviceIndex => $flow) {
            $lines[] = sprintf('    subgraph s%d ["%s"]', $serviceIndex, MermaidText::escape($flow->name));
            foreach ($flow->graph->nodes() as $nodeIndex => $node) {
                $ids[$flow->name][$node->id] = sprintf('s%dn%d', $serviceIndex, $nodeIndex);
                $lines[] = '        ' . $ids[$flow->name][$node->id] . self::shape($node, $highlight?->stateOf($flow->name, $node->id) === null ? null : $highlight->durationLabelOf($flow->name, $node->id));
            }
            $lines[] = '    end';
        }
        $edges = [];
        foreach ($shown as $flow) {
            foreach ($flow->graph->edges() as $edge) {
                $line = sprintf('    %s %s %s', $ids[$flow->name][$edge->from], self::arrow($edge), $ids[$flow->name][$edge->to]);
                $edges[$line] = ($edges[$line] ?? false) || ($highlight?->passes($flow->name, $edge->from, $edge->to) ?? false);
            }
        }
        foreach (DistributedLinks::between($flows) as $link) {
            if (isset($ids[$link->fromService][$link->fromNodeId], $ids[$link->toService][$link->toNodeId])) {
                $line = sprintf('    %s %s|"%s"| %s', $ids[$link->fromService][$link->fromNodeId], $link->kind === 'reply' ? '-.->' : '==>', MermaidText::escape($link->label()), $ids[$link->toService][$link->toNodeId]);
                $edges[$line] = ($edges[$line] ?? false) || ($highlight?->traverses($link) ?? false);
            }
        }

        array_push($lines, ...array_keys($edges));

        return implode("\n", [...$lines, ...self::highlightLines($shown, $ids, array_values($edges), $highlight)]) . "\n";
    }

    /**
     * @param list<ServiceFlow> $shown
     * @param array<string, array<string, string>> $ids
     * @param list<bool> $highlightedEdges
     * @return list<string>
     */
    private static function highlightLines(array $shown, array $ids, array $highlightedEdges, ?TraceHighlight $highlight): array
    {
        if ($highlight === null) {
            return [];
        }
        $lines = [
            '    classDef ' . TraceHighlight::PASSED . ' fill:#d1fae5,stroke:#047857,stroke-width:3px',
            '    classDef ' . TraceHighlight::PENDING . ' fill:#fef3c7,stroke:#b45309,stroke-width:3px,stroke-dasharray:4 2',
            '    classDef ' . TraceHighlight::FAILED . ' fill:#fee2e2,stroke:#b91c1c,stroke-width:3px',
        ];
        foreach ([TraceHighlight::PASSED, TraceHighlight::PENDING, TraceHighlight::FAILED] as $state) {
            $nodeIds = [];
            foreach ($shown as $flow) {
                foreach ($flow->graph->nodes() as $node) {
                    if ($highlight->stateOf($flow->name, $node->id) === $state) {
                        $nodeIds[] = $ids[$flow->name][$node->id];
                    }
                }
            }
            if ($nodeIds !== []) {
                $lines[] = sprintf('    class %s %s', implode(',', $nodeIds), $state);
            }
        }
        $edgeIndexes = array_keys(array_filter($highlightedEdges));
        if ($edgeIndexes !== []) {
            $lines[] = sprintf('    linkStyle %s stroke:#047857,stroke-width:3px', implode(',', $edgeIndexes));
        }

        return $lines;
    }

    private static function shape(FlowNode $node, ?string $duration = null): string
    {
        $label = ($node->kind->isHandler() ? ucfirst($node->kind->value) . '<br/>' : '') . MermaidText::escape($node->label) . ($duration === null ? '' : '<br/>' . MermaidText::escape($duration));

        return match ($node->kind) {
            FlowNodeKind::COMMAND => sprintf('>"%s"]', $label),
            FlowNodeKind::EVENT => sprintf('(["%s"])', $label),
            FlowNodeKind::QUERY => sprintf('{{"%s"}}', $label),
            FlowNodeKind::CHANNEL => sprintf('[("Channel<br/>%s")]', $label),
            FlowNodeKind::DISTRIBUTED_BUS => sprintf('[["%s"]]', $label),
            default => sprintf('["%s"]', $label),
        };
    }

    private static function arrow(FlowEdge $edge): string
    {
        $label = match ($edge->kind) {
            FlowEdgeKind::EMITS => 'emits',
            FlowEdgeKind::ASYNCHRONOUS => 'async',
            FlowEdgeKind::SENDS_TO => $edge->isInferred() ? 'sends' : 'to ' . ($edge->attributes['targetService'] ?? ''),
            FlowEdgeKind::ROUTED_TO, FlowEdgeKind::INPUT => '',
            default => $edge->kind->value,
        };
        $labelPart = $label === '' ? '' : '|"' . MermaidText::escape($label) . '"|';

        return ($edge->kind === FlowEdgeKind::ASYNCHRONOUS ? '==>' : ($edge->isInferred() ? '-.->' : '-->')) . $labelPart;
    }
}
