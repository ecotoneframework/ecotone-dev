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
     * @param list<string> $collapsed
     * @return array<string, array{title: string, service: string, node?: string, collapsed?: true, current?: true}>
     */
    public static function nodeDetails(array $flows, array $shownServices, ?FlowFocus $focus = null, array $collapsed = []): array
    {
        $details = [];
        foreach (self::shownOf($flows, $shownServices) as $serviceIndex => $flow) {
            $details[sprintf('s%d', $serviceIndex)] = ['title' => $flow->name, 'service' => $flow->name];
            if (in_array($flow->name, $collapsed, true)) {
                $details[sprintf('s%dc', $serviceIndex)] = ['title' => sprintf('%s: %d nodes, collapsed', $flow->name, self::countShown($flow, $focus)), 'service' => $flow->name, 'collapsed' => true];
                continue;
            }
            foreach ($flow->graph->nodes() as $nodeIndex => $node) {
                if ($focus?->includes($flow->name, $node->id) === false) {
                    continue;
                }
                $details[sprintf('s%dn%d', $serviceIndex, $nodeIndex)] = ['title' => $node->label, 'service' => $flow->name, 'node' => $node->id, ...($focus?->service === $flow->name && $focus->node->id === $node->id ? ['current' => true] : [])];
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
    public static function overview(array $flows, ?TraceHighlight $highlight = null): Diagram
    {
        $indexes = array_flip(array_map(fn (ServiceFlow $flow) => $flow->name, $flows));
        $nodes = [];
        foreach ($flows as $index => $flow) {
            $nodes[] = new DiagramNode(id: sprintf('s%d', $index), group: null, kind: 'service', label: ShortLabel::of($flow->name), note: sprintf('%d nodes', count($flow->graph->nodes())), state: $highlight?->stateOfService($flow->name));
        }
        $counts = [];
        $traversed = [];
        foreach (DistributedLinks::between($flows) as $link) {
            $key = sprintf('s%d|%s|s%d', $indexes[$link->fromService], $link->kind, $indexes[$link->toService]);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $traversed[$key] = ($traversed[$key] ?? false) || ($highlight?->traverses($link) ?? false);
        }
        $edges = [];
        foreach ($counts as $key => $count) {
            [$from, $kind, $to] = explode('|', $key);
            $edges[] = new DiagramEdge($from, $to, $kind === 'reply' ? DiagramEdge::DASHED : DiagramEdge::THICK, sprintf('%d %s', $count, $kind === 'reply' ? ($count === 1 ? 'reply' : 'replies') : $kind . ($count === 1 ? '' : 's')), $traversed[$key]);
        }

        return new Diagram([], $nodes, $edges);
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
     * @param list<string> $collapsed
     */
    public static function diagram(array $flows, array $shownServices, ?TraceHighlight $highlight = null, ?ServiceScopeFilter $scope = null, ?FlowFocus $focus = null, array $collapsed = [], bool $foldChannels = false): Diagram
    {
        $shown = self::shownOf($flows, $shownServices);
        $ids = [];
        $asyncVia = [];
        $groups = [];
        $nodes = [];
        foreach ($shown as $serviceIndex => $flow) {
            $group = sprintf('s%d', $serviceIndex);
            $groups[] = ['id' => $group, 'title' => $flow->name];
            $greyed = $scope?->isGreyed($flow->name) === true;
            if (in_array($flow->name, $collapsed, true)) {
                $nodes[] = new DiagramNode(id: $group . 'c', group: $group, kind: 'service', label: ShortLabel::of($flow->name), note: sprintf('%d nodes, collapsed', self::countShown($flow, $focus)), state: $highlight?->stateOfService($flow->name), greyed: $greyed, focused: $focus?->service === $flow->name);
                foreach ($flow->graph->nodes() as $node) {
                    if ($focus?->includes($flow->name, $node->id) !== false) {
                        $ids[$flow->name][$node->id] = $group . 'c';
                    }
                }
                continue;
            }
            $folded = $foldChannels ? self::foldedChannels($flow, $focus) : [];
            foreach ($folded as $channelId => $handlers) {
                foreach ($handlers as $handlerId) {
                    $asyncVia[$flow->name][$handlerId] = $flow->graph->node($channelId)->label;
                }
            }
            foreach ($flow->graph->nodes() as $nodeIndex => $node) {
                if ($focus?->includes($flow->name, $node->id) === false || isset($folded[$node->id])) {
                    continue;
                }
                $id = sprintf('s%dn%d', $serviceIndex, $nodeIndex);
                $ids[$flow->name][$node->id] = $id;
                $state = $highlight?->stateOf($flow->name, $node->id);
                $nodes[] = new DiagramNode(
                    id: $id,
                    group: $group,
                    kind: $node->kind->value,
                    label: ShortLabel::of($node->label),
                    note: $state === null ? null : $highlight->durationLabelOf($flow->name, $node->id),
                    state: $state,
                    greyed: $greyed,
                    focused: $focus?->service === $flow->name && $focus->node->id === $node->id,
                    step: $state === null ? null : $highlight->stepOf($flow->name, $node->id),
                );
            }
            foreach ($flow->graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS) as $edge) {
                if (isset($ids[$flow->name][$edge->to]) && ! isset($ids[$flow->name][$edge->from]) && $flow->graph->node($edge->from)->kind === FlowNodeKind::CHANNEL) {
                    $asyncVia[$flow->name][$edge->to] = $flow->graph->node($edge->from)->label;
                }
            }
        }
        $edges = [];
        foreach ($shown as $flow) {
            foreach ($flow->graph->edges() as $edge) {
                if (in_array($flow->name, $collapsed, true) || ! isset($ids[$flow->name][$edge->from], $ids[$flow->name][$edge->to])) {
                    continue;
                }
                $channel = $edge->kind === FlowEdgeKind::ASYNCHRONOUS ? null : ($asyncVia[$flow->name][$edge->to] ?? null);
                [$arrow, $label] = $channel === null ? self::arrow($edge, $flow->graph->node($edge->to)) : [DiagramEdge::THICK, 'async: ' . $channel];
                $drawn = new DiagramEdge($ids[$flow->name][$edge->from], $ids[$flow->name][$edge->to], $arrow, $label);
                $edges[$drawn->key()] = ($edges[$drawn->key()] ?? $drawn)->orHighlighted($highlight?->passes($flow->name, $edge->from, $edge->to) ?? false);
            }
        }
        foreach (DistributedLinks::between($flows) as $link) {
            if (isset($ids[$link->fromService][$link->fromNodeId], $ids[$link->toService][$link->toNodeId])) {
                $drawn = new DiagramEdge($ids[$link->fromService][$link->fromNodeId], $ids[$link->toService][$link->toNodeId], $link->kind === 'reply' ? DiagramEdge::DASHED : DiagramEdge::THICK, $link->label());
                $edges[$drawn->key()] = ($edges[$drawn->key()] ?? $drawn)->orHighlighted($highlight?->traverses($link) ?? false);
            }
        }

        return new Diagram($groups, $nodes, array_values($edges));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function foldedChannels(ServiceFlow $flow, ?FlowFocus $focus): array
    {
        $shown = fn (string $nodeId): bool => $focus?->includes($flow->name, $nodeId) !== false;
        $folded = [];
        foreach ($flow->graph->nodesOfKind(FlowNodeKind::CHANNEL) as $channel) {
            $outgoing = $flow->graph->edgesFrom($channel->id);
            if (! $shown($channel->id) || $outgoing === [] || $flow->graph->edgesTo($channel->id) !== []) {
                continue;
            }
            $handlers = [];
            foreach ($outgoing as $edge) {
                $fedOtherwise = array_filter($flow->graph->edgesTo($edge->to), fn (FlowEdge $into) => $into->kind !== FlowEdgeKind::ASYNCHRONOUS && $shown($into->from));
                if ($edge->kind === FlowEdgeKind::ASYNCHRONOUS && ! $shown($edge->to)) {
                    continue;
                }
                if ($edge->kind !== FlowEdgeKind::ASYNCHRONOUS || $fedOtherwise === []) {
                    continue 2;
                }
                $handlers[] = $edge->to;
            }
            if ($handlers !== []) {
                $folded[$channel->id] = $handlers;
            }
        }

        return $folded;
    }

    private static function countShown(ServiceFlow $flow, ?FlowFocus $focus): int
    {
        return count(array_filter($flow->graph->nodes(), fn (FlowNode $node) => $focus?->includes($flow->name, $node->id) !== false));
    }

    /**
     * @return array{string, string}
     */
    public static function arrow(FlowEdge $edge, ?FlowNode $target = null): array
    {
        $label = match ($edge->kind) {
            FlowEdgeKind::EMITS => $target !== null && $target->kind !== FlowNodeKind::EVENT ? 'sends' : 'emits',
            FlowEdgeKind::ASYNCHRONOUS => 'async',
            FlowEdgeKind::SENDS_TO => match (true) {
                $edge->isInferred() => isset($edge->attributes['targetService']) ? 'sends to ' . $edge->attributes['targetService'] : 'sends',
                ($edge->attributes['via'] ?? null) === 'replyRoutingKey' => 'replies',
                default => 'to ' . ($edge->attributes['targetService'] ?? ''),
            },
            FlowEdgeKind::PROJECTS_INTO => 'projects into',
            FlowEdgeKind::ROUTED_TO, FlowEdgeKind::INPUT => '',
            default => $edge->kind->value,
        };

        return [$edge->kind === FlowEdgeKind::ASYNCHRONOUS ? DiagramEdge::THICK : ($edge->isInferred() ? DiagramEdge::DASHED : DiagramEdge::SOLID), $label];
    }
}
