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
     * @return array<string, array{title: string, service: string, node?: string}>
     */
    public static function nodeDetails(array $flows, array $shownServices, ?FlowFocus $focus = null): array
    {
        $details = [];
        foreach (self::shownOf($flows, $shownServices) as $serviceIndex => $flow) {
            $details[sprintf('s%d', $serviceIndex)] = ['title' => $flow->name, 'service' => $flow->name];
            foreach ($flow->graph->nodes() as $nodeIndex => $node) {
                if ($focus?->includes($flow->name, $node->id) === false) {
                    continue;
                }
                $details[sprintf('s%dn%d', $serviceIndex, $nodeIndex)] = ['title' => $node->label, 'service' => $flow->name, 'node' => $node->id];
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
    public static function overview(array $flows, ?TraceHighlight $highlight = null): string
    {
        $indexes = array_flip(array_map(fn (ServiceFlow $flow) => $flow->name, $flows));
        $lines = ['flowchart LR'];
        $byState = [];
        foreach ($flows as $index => $flow) {
            $lines[] = sprintf('    s%d["%s"]', $index, MermaidText::escape(ShortLabel::of($flow->name)));
            $state = $highlight?->stateOfService($flow->name);
            if ($state !== null) {
                $byState[$state][] = sprintf('s%d', $index);
            }
        }
        $counts = [];
        $traversed = [];
        foreach (DistributedLinks::between($flows) as $link) {
            $key = sprintf('s%d|%s|s%d', $indexes[$link->fromService], $link->kind, $indexes[$link->toService]);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $traversed[$key] = ($traversed[$key] ?? false) || ($highlight?->traverses($link) ?? false);
        }
        foreach ($counts as $key => $count) {
            [$from, $kind, $to] = explode('|', $key);
            $lines[] = sprintf('    %s %s|"%d %s"| %s', $from, $kind === 'reply' ? '-.->' : '==>', $count, $kind === 'reply' ? ($count === 1 ? 'reply' : 'replies') : $kind . ($count === 1 ? '' : 's'), $to);
        }
        if ($highlight !== null) {
            array_push($lines, ...self::highlightClassLines($byState), ...self::highlightedLinkLines(array_values($traversed)));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, list<string>> $nodeIdsByState
     * @return list<string>
     */
    private static function highlightClassLines(array $nodeIdsByState): array
    {
        $lines = [];
        foreach ([TraceHighlight::PASSED, TraceHighlight::PENDING, TraceHighlight::FAILED] as $state) {
            $lines[] = '    classDef ' . $state . ' stroke-width:3px';
        }
        foreach ([TraceHighlight::PASSED, TraceHighlight::PENDING, TraceHighlight::FAILED] as $state) {
            if (isset($nodeIdsByState[$state])) {
                $lines[] = sprintf('    class %s %s', implode(',', $nodeIdsByState[$state]), $state);
            }
        }

        return $lines;
    }

    /**
     * @param list<bool> $highlightedEdges
     * @return list<string>
     */
    private static function highlightedLinkLines(array $highlightedEdges): array
    {
        $indexes = array_keys(array_filter($highlightedEdges));

        return $indexes === [] ? [] : [sprintf('    linkStyle %s stroke-width:3px', implode(',', $indexes))];
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
    public static function toMermaid(array $flows, array $shownServices, ?TraceHighlight $highlight = null, ?ServiceScopeFilter $scope = null, ?FlowFocus $focus = null): string
    {
        $shown = self::shownOf($flows, $shownServices);
        $ids = [];
        $lines = ['flowchart LR'];
        foreach ($shown as $serviceIndex => $flow) {
            $lines[] = sprintf('    subgraph s%d ["%s"]', $serviceIndex, MermaidText::escape($flow->name));
            foreach ($flow->graph->nodes() as $nodeIndex => $node) {
                if ($focus?->includes($flow->name, $node->id) === false) {
                    continue;
                }
                $ids[$flow->name][$node->id] = sprintf('s%dn%d', $serviceIndex, $nodeIndex);
                $lines[] = '        ' . $ids[$flow->name][$node->id] . self::shape($node, $highlight?->stateOf($flow->name, $node->id) === null ? null : $highlight->durationLabelOf($flow->name, $node->id));
            }
            $lines[] = '    end';
        }
        $edges = [];
        foreach ($shown as $flow) {
            foreach ($flow->graph->edges() as $edge) {
                if (! isset($ids[$flow->name][$edge->from], $ids[$flow->name][$edge->to])) {
                    continue;
                }
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

        $greyedLines = [];
        foreach ($shown as $flow) {
            if ($scope?->isGreyed($flow->name) === true) {
                array_push($greyedLines, ...array_map(static fn (string $id): string => $id, array_values($ids[$flow->name] ?? [])));
            }
        }
        if ($greyedLines !== []) {
            $greyedLines = ['    classDef greyed opacity:0.45', '    class ' . implode(',', $greyedLines) . ' greyed'];
        }

        $focusedLines = $focus === null ? [] : ['    classDef focused stroke-width:4px', sprintf('    class %s focused', $ids[$focus->service][$focus->node->id])];

        return implode("\n", [...$lines, ...self::highlightLines($shown, $ids, array_values($edges), $highlight), ...$greyedLines, ...$focusedLines]) . "\n";
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
        $byState = [];
        foreach ($shown as $flow) {
            foreach ($flow->graph->nodes() as $node) {
                $state = $highlight->stateOf($flow->name, $node->id);
                if ($state !== null && isset($ids[$flow->name][$node->id])) {
                    $byState[$state][] = $ids[$flow->name][$node->id];
                }
            }
        }

        return [...self::highlightClassLines($byState), ...self::highlightedLinkLines($highlightedEdges)];
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
