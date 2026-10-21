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
    public static function toMermaid(array $flows, array $shownServices): string
    {
        $shown = array_values(array_filter($flows, fn (ServiceFlow $flow) => in_array($flow->pulseName, $shownServices, true)));
        $ids = [];
        $lines = ['flowchart LR'];
        foreach ($shown as $flow) {
            $lines[] = sprintf('    subgraph %s ["%s"]', self::identifier($flow->pulseName), self::text($flow->pulseName));
            foreach ($flow->graph->nodes() as $node) {
                $ids[$flow->pulseName][$node->id] = self::identifier($flow->pulseName . '__' . $node->id);
                $lines[] = '        ' . $ids[$flow->pulseName][$node->id] . self::shape($node);
            }
            $lines[] = '    end';
        }
        foreach ($shown as $flow) {
            foreach ($flow->graph->edges() as $edge) {
                $lines[] = sprintf('    %s %s %s', $ids[$flow->pulseName][$edge->from], self::arrow($edge), $ids[$flow->pulseName][$edge->to]);
            }
        }
        foreach (DistributedLinks::between($flows) as $link) {
            if (isset($ids[$link->fromService][$link->fromNodeId], $ids[$link->toService][$link->toNodeId])) {
                $lines[] = sprintf('    %s ==>|"distributed %s"| %s', $ids[$link->fromService][$link->fromNodeId], $link->kind, $ids[$link->toService][$link->toNodeId]);
            }
        }

        return implode("\n", array_values(array_unique($lines))) . "\n";
    }

    private static function identifier(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $value);
    }

    private static function text(string $text): string
    {
        return str_replace(['"', '|'], ['#quot;', '#124;'], $text);
    }

    private static function shape(FlowNode $node): string
    {
        $label = self::text($node->kind->isHandler() ? ucfirst($node->kind->value) . '<br/>' . $node->label : $node->label);

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
        $labelPart = $label === '' ? '' : '|"' . self::text($label) . '"|';

        return ($edge->kind === FlowEdgeKind::ASYNCHRONOUS ? '==>' : ($edge->isInferred() ? '-.->' : '-->')) . $labelPart;
    }
}
