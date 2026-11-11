<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

final class Diagram
{
    public const SOLID = 'solid';
    public const DASHED = 'dashed';
    public const THICK = 'thick';

    /**
     * @var array<string, array{service: string, name: string, label: string, kind: string, state: ?string, greyed: bool, focused: bool, current: bool, href: ?string, title: ?string, step: ?int}>
     */
    public array $nodes = [];

    /**
     * @var list<array{from: string, to: string, arrow: string, label: string, highlighted: bool}>
     */
    public array $edges = [];

    /**
     * @var list<string>
     */
    public array $services = [];

    public readonly string $text;

    public function __construct(Crawler $diagram)
    {
        $this->text = $diagram->text();
        foreach ($diagram->filter('[data-diagram-group]') as $group) {
            assert($group instanceof DOMElement);
            $this->services[] = $group->getAttribute('data-service');
        }
        foreach ($diagram->filter('[data-diagram-node]') as $node) {
            assert($node instanceof DOMElement);
            $crawler = new Crawler($node);
            $group = $crawler->closest('[data-diagram-group]');
            $kind = $node->getAttribute('data-kind');
            $prefix = match (true) {
                str_ends_with($kind, 'Handler') || in_array($kind, ['router', 'splitter', 'transformer', 'orchestrator'], true) => ucfirst($kind) . ' ',
                $kind === 'channel' => 'Channel ',
                default => '',
            };
            $note = $crawler->filter('[data-diagram-note]');
            $step = $crawler->filter('[data-diagram-step]');
            $this->nodes[$node->getAttribute('data-diagram-node')] = [
                'service' => $group === null ? '' : (string) $group->attr('data-service'),
                'name' => $crawler->filter('[data-diagram-label]')->text(),
                'label' => $prefix . $crawler->filter('[data-diagram-label]')->text() . ($note->count() === 0 ? '' : ' ' . $note->text()),
                'kind' => $kind,
                'state' => $node->hasAttribute('data-trace-state') ? $node->getAttribute('data-trace-state') : null,
                'greyed' => $node->hasAttribute('data-greyed'),
                'focused' => $node->hasAttribute('data-focused'),
                'current' => $node->getAttribute('aria-current') === 'true',
                'href' => $node->hasAttribute('href') ? $node->getAttribute('href') : null,
                'title' => $node->hasAttribute('title') ? $node->getAttribute('title') : null,
                'step' => $step->count() === 0 ? null : (int) $step->text(),
            ];
        }
        foreach ($diagram->filter('[data-diagram-edge]') as $edge) {
            assert($edge instanceof DOMElement);
            $this->edges[] = [
                'from' => $edge->getAttribute('data-from'),
                'to' => $edge->getAttribute('data-to'),
                'arrow' => $edge->getAttribute('data-arrow'),
                'label' => $edge->getAttribute('data-label'),
                'highlighted' => $edge->hasAttribute('data-highlighted'),
            ];
        }
    }

    public static function fromPage(Crawler $page): self
    {
        return new self($page->filter('[data-diagram]')->first());
    }

    public function hasEdge(string $fromService, string $fromLabel, string $label, string $toService, string $toLabel, ?string $arrow = null): bool
    {
        return $this->edge($fromService, $fromLabel, $label, $toService, $toLabel, $arrow) !== null;
    }

    public function isEdgeHighlighted(string $fromService, string $fromLabel, string $label, string $toService, string $toLabel): bool
    {
        return $this->edge($fromService, $fromLabel, $label, $toService, $toLabel, null)['highlighted'] ?? false;
    }

    public function nodesOf(string $service): int
    {
        return count(array_filter($this->nodes, fn (array $node) => $node['service'] === $service));
    }

    /**
     * @return list<string>
     */
    public function focused(): array
    {
        return array_map('strval', array_keys(array_filter($this->nodes, fn (array $node) => $node['focused'])));
    }

    /**
     * @return list<string>
     */
    public function greyed(): array
    {
        return array_map('strval', array_keys(array_filter($this->nodes, fn (array $node) => $node['greyed'])));
    }

    /**
     * @return list<string>
     */
    public function nodesInState(string $state): array
    {
        return array_values(array_map(fn (array $node) => $node['service'] . '|' . $node['label'], array_filter($this->nodes, fn (array $node) => $node['state'] === $state)));
    }

    public function hasNodeInState(string $state, string $service, string $labelFragment): bool
    {
        foreach ($this->nodesInState($state) as $node) {
            if (str_starts_with($node, $service . '|') && str_contains($node, $labelFragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{from: string, to: string, arrow: string, label: string, highlighted: bool}|null
     */
    private function edge(string $fromService, string $fromLabel, string $label, string $toService, string $toLabel, ?string $arrow): ?array
    {
        foreach ($this->edges as $edge) {
            $from = $this->nodes[$edge['from']] ?? null;
            $to = $this->nodes[$edge['to']] ?? null;
            if ($from !== null && $to !== null && $edge['label'] === $label && ($arrow === null || $edge['arrow'] === $arrow)
                && $from['service'] === $fromService && str_contains($from['label'], $fromLabel)
                && $to['service'] === $toService && str_contains($to['label'], $toLabel)) {
                return $edge;
            }
        }

        return null;
    }
}
