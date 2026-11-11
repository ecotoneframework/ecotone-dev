<?php

declare(strict_types=1);

namespace App\Graph;

final readonly class Diagram
{
    /**
     * @param list<array{id: string, title: string}> $groups
     * @param list<DiagramNode> $nodes
     * @param list<DiagramEdge> $edges
     */
    public function __construct(
        public array $groups,
        public array $nodes,
        public array $edges,
    ) {
    }

    /**
     * @param array<string, array{href?: string, title?: string, current?: true}> $links
     */
    public function linked(array $links): self
    {
        return new self($this->groups, array_map(static fn (DiagramNode $node): DiagramNode => isset($links[$node->id]) ? $node->linked($links[$node->id]) : $node, $this->nodes), $this->edges);
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /**
     * @return list<DiagramNode>
     */
    public function nodesIn(?string $group): array
    {
        return array_values(array_filter($this->nodes, static fn (DiagramNode $node): bool => $node->group === $group));
    }

    public function node(string $id): ?DiagramNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }

    public function hasTrace(): bool
    {
        return array_any($this->nodes, static fn (DiagramNode $node): bool => $node->state !== null);
    }
}
