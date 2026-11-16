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
    public const FORMAT_VERSION = 3;
    private const ANONYMOUS_CLASS = '/@anonymous\x00([^\x00:]+):(\d+)\$[0-9a-f]+/';
    private const GENERATED_ID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';

    /**
     * @param list<array{depth: int, name: string, destination?: string, kind: string, status: string}> $trace
     * @param array<string, array<string, scalar>> $ledger kind and name of a registration => its entry
     * @param array<string, list<string>> $chains handler name of the ledger => its built layers, outermost first
     * @param list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}>}> $execution
     */
    private function __construct(
        public readonly string $scenario,
        public readonly MessageFlowGraph $wiring,
        public readonly array $trace,
        public readonly array $ledger,
        public readonly array $chains,
        public readonly array $execution,
    ) {
    }

    /**
     * @param Span[] $spans
     * @param array{ledger: list<array<string, scalar>>, chains: array<string, list<string>>} $build
     */
    public static function of(string $scenario, MessageFlowGraph $wiring, array $spans, array $build): self
    {
        $anonymousClasses = self::anonymousClassNamesIn([$wiring->toArray(), $build]);
        $nodes = array_map(fn (FlowNode $node): FlowNode => FlowNode::fromArray(self::withStableNames($node->toArray(), $anonymousClasses)), $wiring->nodes());
        usort($nodes, fn (FlowNode $first, FlowNode $second): int => strcmp($first->id, $second->id));
        $edges = array_map(fn (FlowEdge $edge): FlowEdge => FlowEdge::fromArray(self::withStableNames($edge->toArray(), $anonymousClasses)), $wiring->edges());
        usort($edges, fn (FlowEdge $first, FlowEdge $second): int => strcmp($first->identity(), $second->identity()));
        $ledger = [];
        foreach (self::withStableNames($build['ledger'], $anonymousClasses) as $entry) {
            $ledger[$entry['kind'] . ':' . $entry['name']] = $entry;
        }
        $chains = self::withStableNames($build['chains'], $anonymousClasses);
        ksort($ledger, SORT_STRING);
        ksort($chains, SORT_STRING);

        return new self($scenario, MessageFlowGraph::create($nodes, $edges), self::withStableNames(FlowTrace::stepsOf($spans), $anonymousClasses), $ledger, $chains, self::withStableNames(FlowExecution::of($spans)->processes, $anonymousClasses));
    }

    public static function fromJson(string $json, string $file, ?self $shared = null): self
    {
        $stored = json_decode($json, true);
        if (! is_array($stored) || ! is_string($stored['scenario'] ?? null) || ! is_array($stored['wiring']['nodes'] ?? null) || ! is_array($stored['wiring']['edges'] ?? null) || ! is_array($stored['build']['ledger'] ?? null) || ! is_array($stored['build']['chains'] ?? null) || ! is_array($stored['trace'] ?? null) || ! is_array($stored['execution'] ?? null)) {
            throw InvalidArgumentException::create("Flow snapshot file {$file} does not hold `scenario`, `wiring`, `build`, `trace` and `execution`, so it was not written by this version of the build flow tooling. Restore it from version control, or regenerate every snapshot with `php bin/build-flow.php snapshot`.");
        }
        $nodes = [];
        $edges = [];
        $ledger = [];
        $chains = [];
        if (isset($stored['wiring']['withoutNodes'], $stored['wiring']['withoutEdges']) || isset($stored['build']['withoutLedger'], $stored['build']['withoutChains'])) {
            if ($shared === null) {
                throw InvalidArgumentException::create("Flow snapshot file {$file} stores only what its scenario adds to what every application gets, but " . dirname($file) . '/' . FlowSnapshots::SHARED_FILE . ' is missing, so its whole wiring and build cannot be read. Restore that file from version control, or regenerate every snapshot with `php bin/build-flow.php snapshot`.');
            }
            foreach ($shared->wiring->nodes() as $node) {
                $nodes[$node->id] = $node;
            }
            foreach ($shared->wiring->edges() as $edge) {
                $edges[$edge->identity()] = $edge;
            }
            $nodes = array_diff_key($nodes, array_flip($stored['wiring']['withoutNodes'] ?? []));
            $edges = array_diff_key($edges, array_flip($stored['wiring']['withoutEdges'] ?? []));
            $ledger = array_diff_key($shared->ledger, array_flip($stored['build']['withoutLedger'] ?? []));
            $chains = array_diff_key($shared->chains, array_flip($stored['build']['withoutChains'] ?? []));
        }
        foreach ($stored['wiring']['nodes'] as $node) {
            $nodes[$node['id']] = FlowNode::fromArray($node);
        }
        foreach ($stored['wiring']['edges'] as $edge) {
            $edge = FlowEdge::fromArray($edge);
            $edges[$edge->identity()] = $edge;
        }
        foreach ($stored['build']['ledger'] as $entry) {
            $ledger[$entry['kind'] . ':' . $entry['name']] = $entry;
        }
        foreach ($stored['build']['chains'] as $chain) {
            $chains[$chain['handler']] = $chain['layers'];
        }
        ksort($nodes, SORT_STRING);
        ksort($edges, SORT_STRING);
        ksort($ledger, SORT_STRING);
        ksort($chains, SORT_STRING);

        return new self($stored['scenario'], MessageFlowGraph::create(array_values($nodes), array_values($edges)), $stored['trace'], $ledger, $chains, self::executionOf($stored['execution'], $file));
    }

    public static function sharedFromJson(string $json, string $file): self
    {
        $stored = json_decode($json, true);
        if (! is_array($stored) || ! is_array($stored['wiring']['nodes'] ?? null) || ! is_array($stored['wiring']['edges'] ?? null) || ! is_array($stored['build']['ledger'] ?? null) || ! is_array($stored['build']['chains'] ?? null)) {
            throw InvalidArgumentException::create("Shared file {$file} does not hold `wiring` and `build`, so it was not written by this version of the build flow tooling. Restore it from version control, or regenerate every snapshot with `php bin/build-flow.php snapshot`.");
        }

        return self::fromJson(json_encode(['scenario' => 'what every application gets', 'trace' => [], 'execution' => []] + $stored, JSON_THROW_ON_ERROR), $file);
    }

    public static function emptyFor(string $scenario): self
    {
        return new self($scenario, MessageFlowGraph::create([], []), [], [], [], []);
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
        return $this->encodedWith(self::linesOf($this->wiring->nodes()), self::linesOf($this->wiring->edges()), [], $this->ledgerLines(), $this->chainLines(), []);
    }

    public function toJsonBeside(self $shared): string
    {
        $sharedNodes = self::linesOf($shared->wiring->nodes());
        $sharedEdges = self::linesOf($shared->wiring->edges());
        $nodes = self::linesOf($this->wiring->nodes());
        $edges = self::linesOf($this->wiring->edges());

        return $this->encodedWith(
            array_diff_assoc($nodes, $sharedNodes),
            array_diff_assoc($edges, $sharedEdges),
            [
                '        "withoutNodes": ' . self::encoded(array_keys(array_diff_key($sharedNodes, $nodes))),
                '        "withoutEdges": ' . self::encoded(array_keys(array_diff_key($sharedEdges, $edges))),
            ],
            array_diff_assoc($this->ledgerLines(), $shared->ledgerLines()),
            array_diff_assoc($this->chainLines(), $shared->chainLines()),
            [
                '        "withoutLedger": ' . self::encoded(array_keys(array_diff_key($shared->ledger, $this->ledger))),
                '        "withoutChains": ' . self::encoded(array_keys(array_diff_key($shared->chains, $this->chains))),
            ],
        );
    }

    public function sharedJson(): string
    {
        return implode("\n", [
            '{',
            '    "formatVersion": ' . self::FORMAT_VERSION . ',',
            '    "wiring": {',
            '        "nodes": ' . self::oneLineEach(self::linesOf($this->wiring->nodes()), '        ') . ',',
            '        "edges": ' . self::oneLineEach(self::linesOf($this->wiring->edges()), '        '),
            '    },',
            '    "build": {',
            '        "ledger": ' . self::oneLineEach($this->ledgerLines(), '        ') . ',',
            '        "chains": ' . self::oneLineEach($this->chainLines(), '        '),
            '    }',
            '}',
            '',
        ]);
    }

    /**
     * @return array<string, string> kind and name of a registration => its stored line
     */
    private function ledgerLines(): array
    {
        return array_map(self::encoded(...), $this->ledger);
    }

    /**
     * @return array<string, string> handler name => its stored line
     */
    private function chainLines(): array
    {
        $lines = [];
        foreach ($this->chains as $handler => $layers) {
            $lines[$handler] = self::encoded(['handler' => $handler, 'layers' => $layers]);
        }

        return $lines;
    }

    /**
     * @param array<string, string> $nodeLines
     * @param array<string, string> $edgeLines
     * @param list<string> $withoutSharedWiring
     * @param array<string, string> $ledgerLines
     * @param array<string, string> $chainLines
     * @param list<string> $withoutSharedBuild
     */
    private function encodedWith(array $nodeLines, array $edgeLines, array $withoutSharedWiring, array $ledgerLines, array $chainLines, array $withoutSharedBuild): string
    {
        return implode("\n", [
            '{',
            '    "formatVersion": ' . self::FORMAT_VERSION . ',',
            '    "scenario": ' . self::encoded($this->scenario) . ',',
            '    "wiring": {',
            implode(",\n", [
                '        "nodes": ' . self::oneLineEach($nodeLines, '        '),
                '        "edges": ' . self::oneLineEach($edgeLines, '        '),
                ...$withoutSharedWiring,
            ]),
            '    },',
            '    "build": {',
            implode(",\n", [
                '        "ledger": ' . self::oneLineEach($ledgerLines, '        '),
                '        "chains": ' . self::oneLineEach($chainLines, '        '),
                ...$withoutSharedBuild,
            ]),
            '    },',
            '    "trace": ' . self::oneLineEach(array_map(self::encoded(...), $this->trace), '    ') . ',',
            '    "execution": ' . self::oneLineEach(array_map(
                fn (array $process): string => '{"process": ' . self::encoded($process['process']) . ', "steps": ' . self::oneLineEach(array_map(
                    fn (array $step): string => self::encoded(['depth' => $step['depth'], 'label' => $step['label'], ...($step['hints'] === [] ? [] : ['hints' => $step['hints']])]),
                    $process['steps'],
                ), '        ') . '}',
                $this->execution,
            ), '    '),
            '}',
            '',
        ]);
    }

    /**
     * @param list<array{process: string, steps: list<mixed>}> $execution
     * @return list<array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, critical: bool}>}>}>
     */
    private static function executionOf(array $execution, string $file): array
    {
        return array_map(fn (array $process): array => [
            'process' => (string) $process['process'],
            'steps' => array_map(function (mixed $step) use ($file): array {
                if (! is_array($step) || ! is_int($step['depth'] ?? null) || ! is_string($step['label'] ?? null)) {
                    throw InvalidArgumentException::create("Flow snapshot file {$file} holds steps of `execution` without a depth and a label, so it was written by an earlier version of the build flow tooling. Regenerate every snapshot with `php bin/build-flow.php snapshot`.");
                }

                return ['depth' => $step['depth'], 'label' => $step['label'], 'hints' => array_map(fn (array $hint): array => ['key' => (string) $hint['key'], 'value' => (string) $hint['value'], 'critical' => (bool) $hint['critical']], $step['hints'] ?? [])];
            }, $process['steps']),
        ], $execution);
    }

    /**
     * @param list<FlowNode>|list<FlowEdge> $elements
     * @return array<string, string> node id or edge identity => its stored line
     */
    private static function linesOf(array $elements): array
    {
        $lines = [];
        foreach ($elements as $element) {
            $lines[$element instanceof FlowNode ? $element->id : $element->identity()] = self::encoded(self::withSortedAttributes($element->toArray()));
        }

        return $lines;
    }

    /**
     * @param array<string|int, string> $lines
     */
    private static function oneLineEach(array $lines, string $indentation): string
    {
        if ($lines === []) {
            return '[]';
        }

        return "[\n" . implode(",\n", array_map(fn (string $line): string => $indentation . '    ' . $line, $lines)) . "\n" . $indentation . ']';
    }

    private static function encoded(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string> anonymous class name => name that stays the same wherever the class is declared
     */
    private static function anonymousClassNamesIn(array $recorded): array
    {
        preg_match_all(self::ANONYMOUS_CLASS, self::flattened($recorded), $found, PREG_SET_ORDER);
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
