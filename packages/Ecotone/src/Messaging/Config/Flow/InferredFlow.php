<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;

final class InferredFlow
{
    /** @var array<string, FlowNode> */
    private array $nodes = [];
    /** @var list<FlowEdge> */
    private array $edges;

    public function __construct(MessageFlowGraph $declared)
    {
        foreach ($declared->nodes() as $node) {
            $this->nodes[$node->id] = $node;
        }
        $this->edges = $declared->edges();
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function node(string $id): FlowNode
    {
        return $this->nodes[$id];
    }

    public function addNode(FlowNode $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function addEdge(FlowEdge $edge): void
    {
        $this->edges[] = $edge;
    }

    public function graph(): MessageFlowGraph
    {
        $connected = [];
        foreach ($this->edges as $edge) {
            $connected[$edge->from] = true;
            $connected[$edge->to] = true;
        }

        return MessageFlowGraph::create(
            array_values(array_filter($this->nodes, fn (FlowNode $node) => $node->kind !== FlowNodeKind::CHANNEL || isset($connected[$node->id]))),
            $this->edges,
        );
    }
}
