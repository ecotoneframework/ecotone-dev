<?php

declare(strict_types=1);

namespace App\Graph;

use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;

final readonly class FlowFocus
{
    /**
     * @param array<string, array<string, true>> $members
     * @param list<array{service: string, node: FlowNode}> $upstream
     * @param list<array{service: string, node: FlowNode}> $downstream
     */
    private function __construct(
        public string $service,
        public FlowNode $node,
        private array $members,
        public array $upstream,
        public array $downstream,
    ) {
    }

    /**
     * @param list<ServiceFlow> $flows
     */
    public static function of(array $flows, string $service, string $nodeId): self
    {
        $byName = [];
        foreach ($flows as $flow) {
            $byName[$flow->name] = $flow;
        }
        if (! isset($byName[$service])) {
            throw UnknownFlowFocus::ofService($service . ':' . $nodeId, $service, array_keys($byName));
        }
        if (! $byName[$service]->graph->hasNode($nodeId)) {
            throw UnknownFlowFocus::ofNode($service . ':' . $nodeId, $service, $nodeId);
        }
        $node = $byName[$service]->graph->node($nodeId);
        $links = DistributedLinks::between($flows);
        $isHub = in_array($node->kind, [FlowNodeKind::CHANNEL, FlowNodeKind::DISTRIBUTED_BUS], true);
        $upstream = self::walk($byName, $links, $service, $nodeId, downstream: false, oneStep: $isHub);
        $downstream = self::walk($byName, $links, $service, $nodeId, downstream: true, oneStep: $isHub);
        $members = [$service => [$nodeId => true]];
        foreach ([...$upstream, ...$downstream] as $step) {
            $members[$step['service']][$step['node']->id] = true;
        }

        return new self($service, $node, $members, array_reverse($upstream), $downstream);
    }

    public function includes(string $service, string $nodeId): bool
    {
        return isset($this->members[$service][$nodeId]);
    }

    /**
     * @return list<string>
     */
    public function services(): array
    {
        return array_keys($this->members);
    }

    /**
     * @param array<string, ServiceFlow> $flows
     * @param list<DistributedLink> $links
     * @return list<array{service: string, node: FlowNode}>
     */
    private static function walk(array $flows, array $links, string $service, string $nodeId, bool $downstream, bool $oneStep): array
    {
        $seen = [$service => [$nodeId => true]];
        $queue = [[$service, $nodeId]];
        $steps = [];
        while ($queue !== []) {
            [$currentService, $currentId] = array_shift($queue);
            foreach (self::neighbours($flows[$currentService], $links, $currentService, $currentId, $downstream) as [$nextService, $nextId, $continues]) {
                if (isset($seen[$nextService][$nextId])) {
                    continue;
                }
                $seen[$nextService][$nextId] = true;
                $steps[] = ['service' => $nextService, 'node' => $flows[$nextService]->graph->node($nextId)];
                if ($continues && ! $oneStep) {
                    $queue[] = [$nextService, $nextId];
                }
            }
        }

        return $steps;
    }

    /**
     * @param list<DistributedLink> $links
     * @return list<array{string, string, bool}>
     */
    private static function neighbours(ServiceFlow $flow, array $links, string $service, string $nodeId, bool $downstream): array
    {
        $neighbours = [];
        foreach ($downstream ? $flow->graph->edgesFrom($nodeId) : $flow->graph->edgesTo($nodeId) as $edge) {
            if ($edge->kind === FlowEdgeKind::INTERCEPTED_BY) {
                continue;
            }
            $next = $downstream ? $edge->to : $edge->from;
            $neighbours[] = [$service, $next, $flow->graph->node($next)->kind !== FlowNodeKind::DISTRIBUTED_BUS];
        }
        foreach ($links as $link) {
            if ($downstream && $link->fromService === $service && $link->fromNodeId === $nodeId) {
                $neighbours[] = [$link->toService, $link->toNodeId, true];
            }
            if (! $downstream && $link->toService === $service && $link->toNodeId === $nodeId) {
                $neighbours[] = [$link->fromService, $link->fromNodeId, true];
            }
        }

        return $neighbours;
    }
}
