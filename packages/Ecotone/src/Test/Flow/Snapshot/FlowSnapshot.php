<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Api\Tracing\Span;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class FlowSnapshot
{
    public const FORMAT_VERSION = 1;
    private const ANONYMOUS_CLASS = '/@anonymous\x00([^\x00:]+):(\d+)\$[0-9a-f]+/';
    private const GENERATED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';

    /**
     * @param list<array{depth: int, name: string, destination?: string, kind: string, status: string}> $trace
     */
    private function __construct(
        public readonly string $scenario,
        public readonly MessageFlowGraph $wiring,
        public readonly array $trace,
    ) {
    }

    /**
     * @param Span[] $spans
     */
    public static function of(string $scenario, MessageFlowGraph $wiring, array $spans): self
    {
        $anonymousClasses = self::anonymousClassNamesIn($wiring);
        $nodes = array_map(fn (FlowNode $node): FlowNode => FlowNode::fromArray(self::withStableNames($node->toArray(), $anonymousClasses)), $wiring->nodes());
        usort($nodes, fn (FlowNode $first, FlowNode $second): int => strcmp($first->id, $second->id));
        $edges = array_map(fn (FlowEdge $edge): FlowEdge => FlowEdge::fromArray(self::withStableNames($edge->toArray(), $anonymousClasses)), $wiring->edges());
        usort($edges, fn (FlowEdge $first, FlowEdge $second): int => strcmp($first->identity(), $second->identity()));

        return new self($scenario, MessageFlowGraph::create($nodes, $edges), self::withStableNames(FlowTrace::stepsOf($spans), $anonymousClasses));
    }

    public static function fromJson(string $json, string $file): self
    {
        $stored = json_decode($json, true);
        if (! is_array($stored) || ! is_string($stored['scenario'] ?? null) || ! is_array($stored['wiring']['nodes'] ?? null) || ! is_array($stored['wiring']['edges'] ?? null) || ! is_array($stored['trace'] ?? null)) {
            throw InvalidArgumentException::create("Flow snapshot file {$file} does not hold `scenario`, `wiring` and `trace`, so it was not written by the internal flow tooling. Restore it from version control, or regenerate every snapshot with `php bin/internal-flow.php snapshot`.");
        }

        return new self($stored['scenario'], MessageFlowGraph::fromArray($stored['wiring']), $stored['trace']);
    }

    public static function emptyFor(string $scenario): self
    {
        return new self($scenario, MessageFlowGraph::create([], []), []);
    }

    public function fileName(): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->scenario)), '-');
        if ($name === '') {
            throw InvalidArgumentException::create("Scenario `{$this->scenario}` cannot be recorded, because a snapshot is stored in a file named after the letters and digits of its scenario, and this name has none. Give the scenario a name with at least one letter or digit, for example `projection rebuilt asynchronously`.");
        }

        return $name . '.json';
    }

    public function toJson(): string
    {
        return json_encode([
            'formatVersion' => self::FORMAT_VERSION,
            'scenario' => $this->scenario,
            'wiring' => [
                'nodes' => array_map(fn (FlowNode $node): array => self::withSortedAttributes($node->toArray()), $this->wiring->nodes()),
                'edges' => array_map(fn (FlowEdge $edge): array => self::withSortedAttributes($edge->toArray()), $this->wiring->edges()),
            ],
            'trace' => $this->trace,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @return array<string, string> anonymous class name => name that stays the same wherever the class is declared
     */
    private static function anonymousClassNamesIn(MessageFlowGraph $wiring): array
    {
        preg_match_all(self::ANONYMOUS_CLASS, self::flattened($wiring->toArray()), $found, PREG_SET_ORDER);
        $declarations = [];
        foreach ($found as [$name, $file, $line]) {
            $declarations[$name] = [$file, (int) $line, $name];
        }
        uasort($declarations, fn (array $first, array $second): int => $first <=> $second);
        $stableNames = [];
        foreach (array_keys($declarations) as $position => $name) {
            $stableNames[$name] = '@anonymous#' . ($position + 1);
        }

        return $stableNames;
    }

    private static function flattened(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value . "\n" : '';
        }
        $text = '';
        foreach ($value as $key => $element) {
            $text .= (is_string($key) ? $key . "\n" : '') . self::flattened($element);
        }

        return $text;
    }

    /**
     * @param array<string, string> $anonymousClasses
     */
    private static function withStableNames(mixed $value, array $anonymousClasses): mixed
    {
        if (is_string($value)) {
            return (string) preg_replace([self::ANONYMOUS_CLASS, self::GENERATED_ID], ['@anonymous', '{generated}'], strtr($value, $anonymousClasses));
        }
        if (! is_array($value)) {
            return $value;
        }
        $stable = [];
        foreach ($value as $key => $element) {
            $stable[is_string($key) ? self::withStableNames($key, $anonymousClasses) : $key] = self::withStableNames($element, $anonymousClasses);
        }

        return $stable;
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>
     */
    private static function withSortedAttributes(array $element): array
    {
        $element['attributes'] = self::sorted($element['attributes']);

        return $element;
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::sorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
