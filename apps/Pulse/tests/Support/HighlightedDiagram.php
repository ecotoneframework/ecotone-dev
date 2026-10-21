<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DomCrawler\Crawler;

final class HighlightedDiagram
{
    public readonly MermaidDiagram $diagram;

    public function __construct(Crawler $page)
    {
        $this->diagram = MermaidDiagram::fromPage($page);
    }

    /**
     * @return list<string>
     */
    public function nodesOf(string $highlight): array
    {
        $labels = [];
        foreach ($this->diagram->lines as $line) {
            if (preg_match('/^    class ([\w,]+) ' . $highlight . '$/', $line, $match) === 1) {
                foreach (explode(',', $match[1]) as $id) {
                    $labels[] = $this->diagram->nodes[$id]['service'] . '|' . $this->diagram->nodes[$id]['label'];
                }
            }
        }

        return $labels;
    }

    public function hasNode(string $highlight, string $service, string $labelFragment): bool
    {
        foreach ($this->nodesOf($highlight) as $node) {
            if (str_starts_with($node, $service . '|') && str_contains($node, $labelFragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public function highlightedEdges(): array
    {
        $indexes = [];
        foreach ($this->diagram->lines as $line) {
            if (preg_match('/^    linkStyle ([\d,]+) /', $line, $match) === 1) {
                array_push($indexes, ...array_map('intval', explode(',', $match[1])));
            }
        }

        return $indexes;
    }

    public function isEdgeHighlighted(string $fromService, string $fromLabel, string $label, string $toService, string $toLabel): bool
    {
        foreach ($this->diagram->edges as $index => $edge) {
            $from = $this->diagram->nodes[$edge['from']] ?? null;
            $to = $this->diagram->nodes[$edge['to']] ?? null;
            if ($from !== null && $to !== null && $edge['label'] === $label
                && $from['service'] === $fromService && str_contains($from['label'], $fromLabel)
                && $to['service'] === $toService && str_contains($to['label'], $toLabel)) {
                return in_array($index, $this->highlightedEdges(), true);
            }
        }

        return false;
    }
}
