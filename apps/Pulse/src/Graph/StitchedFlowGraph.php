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
     * @return array<string, array{title: string, service: string}>
     */
    public static function nodeDetails(array $flows, array $shownServices): array
    {
        $details = [];
        foreach (self::shownOf($flows, $shownServices) as $serviceIndex => $flow) {
            $details[sprintf('s%d', $serviceIndex)] = ['title' => $flow->name, 'service' => $flow->name];
            foreach ($flow->graph->nodes() as $nodeIndex => $node) {
                $details[sprintf('s%dn%d', $serviceIndex, $nodeIndex)] = ['title' => $node->label, 'service' => $flow->name];
            }
        }

        return $details;
    }

    /**
     * @param list<ServiceFlow> $flows
     * @return array<string, array{title: string, service: string}>
     */
    public static function overviewDetails(array $flows): array
    {
        $details = [];
        foreach ($flows as $index => $flow) {
            $details[sprintf('s%d', $index)] = ['title' => sprintf('%s: %d nodes', $flow->name, count($flow->graph->nodes())), 'service' => $flow->name];
        }

        return $details;
    }

    /**
     * @param list<ServiceFlow> $flows
     */
    public static function overview(array $flows): string
    {
        $indexes = array_flip(array_map(fn (ServiceFlow $flow) => $flow->name, $flows));
        $lines = ['flowchart LR'];
        foreach ($flows as $index => $flow) {
            $lines[] = sprintf('    s%d["%s"]', $index, MermaidText::escape(ShortLabel::of($flow->name)));
        }
        $counts = [];
        foreach (DistributedLinks::between($flows) as $link) {
            $key = sprintf('s%d|%s|s%d', $indexes[$link->fromService], $link->kind, $indexes[$link->toService]);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        foreach ($counts as $key => $count) {
            [$from, $kind, $to] = explode('|', $key);
            $lines[] = sprintf('    %s %s|"%d %s"| %s', $from, $kind === 'reply' ? '-.->' : '==>', $count, $kind === 'reply' ? ($count === 1 ? 'reply' : 'replies') : $kind . ($count === 1 ? '' : 's'), $to);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<ServiceFlow> $flows
     * @param list<string> $shownServices
     * @return list<ServiceFlow>
     */
    private static function shownOf(array $flows, array $shownServices): array
    {
        return array_values(array_filter($flows, fn (ServiceFlow $flow) => in_array($flow->name, $shownServices, true)));
    }

    /**
     * @param list<ServiceFlow> $flows
     * @param list<string> $shownServices
     */
    public static function toMermaid(array $flows, array $shownServices, ?TraceHighlight $highlight = null): string
    {
        $shown = self::shownOf($flows, $shownServices);
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
            '    classDef ' . TraceHighlight::PASSED . ' stroke-width:3px',
            '    classDef ' . TraceHighlight::PENDING . ' stroke-width:3px',
            '    classDef ' . TraceHighlight::FAILED . ' stroke-width:3px',
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
            $lines[] = sprintf('    linkStyle %s stroke-width:3px', implode(',', $edgeIndexes));
        }

        return $lines;
    }

    private static function shape(FlowNode $node, ?string $duration = null): string
    {
        $label = ($node->kind->isHandler() ? ucfirst($node->kind->value) . '<br/>' : '') . MermaidText::escape(ShortLabel::of($node->label)) . ($duration === null ? '' : '<br/>' . MermaidText::escape($duration));

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
