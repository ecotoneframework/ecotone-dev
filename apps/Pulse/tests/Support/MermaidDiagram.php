<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DomCrawler\Crawler;

final class MermaidDiagram
{
    /**
     * @var array<string, array{service: string, label: string}>
     */
    public array $nodes = [];

    /**
     * @var list<array{from: string, to: string, arrow: string, label: string}>
     */
    public array $edges = [];

    /**
     * @var list<string>
     */
    public array $lines;

    public function __construct(public readonly string $source)
    {
        $this->lines = explode("\n", rtrim($source));
        $service = null;
        foreach ($this->lines as $line) {
            if (preg_match('/^    subgraph (\w+) \["(.*)"\]$/', $line, $match) === 1) {
                $service = $match[2];
            } elseif ($line === '    end') {
                $service = null;
            } elseif ($service !== null && preg_match('/^        (\w+)(?:>|\(\[|\{\{|\[\(|\[\[|\[)"(.*)"(?:\]|\]\)|\}\}|\)\]|\]\])$/', $line, $match) === 1) {
                $this->nodes[$match[1]] = ['service' => $service, 'label' => $match[2]];
            } elseif (preg_match('/^    (\w+) (-->|-\.->|==>)(?:\|"(.*)"\|)? (\w+)$/', $line, $match) === 1) {
                $this->edges[] = ['from' => $match[1], 'to' => $match[4], 'arrow' => $match[2], 'label' => $match[3]];
            }
        }
    }

    public static function fromPage(Crawler $page): self
    {
        return new self($page->filter('pre.mermaid')->getNode(0)->textContent);
    }

    public function hasEdge(string $fromService, string $fromLabel, string $label, string $toService, string $toLabel, ?string $arrow = null): bool
    {
        foreach ($this->edges as $edge) {
            $from = $this->nodes[$edge['from']] ?? null;
            $to = $this->nodes[$edge['to']] ?? null;
            if ($from !== null && $to !== null && $edge['label'] === $label && ($arrow === null || $edge['arrow'] === $arrow)
                && $from['service'] === $fromService && str_contains($from['label'], $fromLabel)
                && $to['service'] === $toService && str_contains($to['label'], $toLabel)) {
                return true;
            }
        }

        return false;
    }

    public function nodesOf(string $service): int
    {
        return count(array_filter($this->nodes, fn (array $node) => $node['service'] === $service));
    }
}
