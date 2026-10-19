<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Modelling\Config\Routing\BusRoutingMap;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\Flow\SpanTree;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowGraphConformanceTest extends TestCase
{
    private const DECIDED_ONLY_AT_RUNTIME = [
        'orchestrator gateway starting the flow' => 'The steps an orchestrator gateway runs are the routing argument its caller passes at runtime, so no static graph can name them.',
    ];

    #[DataProviderExternal(FlowScenarioCatalogue::class, 'dataProvider')]
    public function test_every_recorded_step_of_a_catalogued_flow_is_a_path_in_the_message_flow_graph(FlowScenario $scenario): void
    {
        if (isset(self::DECIDED_ONLY_AT_RUNTIME[$scenario->name])) {
            self::markTestSkipped(self::DECIDED_ONLY_AT_RUNTIME[$scenario->name]);
        }
        $recorder = new InMemorySpanRecorder();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            $scenario->classesToResolve,
            [...$scenario->services, InMemorySpanRecorder::class => $recorder],
            [...$scenario->extensionObjects, TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        foreach (array_keys($scenario->stages) as $index) {
            self::assertNull($scenario->runStage($index, $ecotone));
        }
        $graph = $ecotone->getMessageFlowGraph();
        $tree = SpanTree::of($recorder->recordedSpans());

        $steps = [];
        foreach ($tree->roots() as $root) {
            $steps = [...$steps, ...self::stepsFrom($root, null, $tree, $graph)];
        }

        $handlerRan = array_filter($tree->all(), fn (Span $span) => isset($span->attributes[SpanAttribute::CODE_FUNCTION_NAME]));
        self::assertTrue($steps !== [] || $handlerRan === [], 'Handlers ran, yet no recorded step could be placed in the message flow graph');
        foreach ($steps as [$from, $to]) {
            self::assertTrue(self::hasPath($graph, $from, $to), "The recorded step {$from} -> {$to} has no path in the message flow graph:\n" . $graph->toMermaid());
        }
    }

    /**
     * @return list<array{string, string}>
     */
    private static function stepsFrom(Span $span, ?string $nearestNode, SpanTree $tree, MessageFlowGraph $graph): array
    {
        $node = self::nodeOf($span, $graph);
        $isChannel = $node !== null && str_starts_with($node, 'channel:');
        $steps = [];
        if ($node !== null && ! $isChannel && $nearestNode !== null && $nearestNode !== $node) {
            $steps[] = [$nearestNode, $node];
        }
        foreach ($tree->childrenOf($span) as $child) {
            $steps = [...$steps, ...self::stepsFrom($child, $node ?? $nearestNode, $tree, $graph)];
        }
        if ($isChannel && $nearestNode !== null && ! str_starts_with($nearestNode, 'channel:')) {
            $reachedBelow = self::nearestNodesBelow($span, $tree, $graph);
            foreach ($reachedBelow === [] ? [$node] : $reachedBelow as $reached) {
                $steps[] = [$nearestNode, $reached];
            }
        }

        return $steps;
    }

    /**
     * @return list<string>
     */
    private static function nearestNodesBelow(Span $span, SpanTree $tree, MessageFlowGraph $graph): array
    {
        $nodes = [];
        foreach ($tree->childrenOf($span) as $child) {
            $node = self::nodeOf($child, $graph);
            $nodes = $node === null || str_starts_with($node, 'channel:')
                ? [...$nodes, ...self::nearestNodesBelow($child, $tree, $graph)]
                : [...$nodes, $node];
        }

        return $nodes;
    }

    private static function nodeOf(Span $span, MessageFlowGraph $graph): ?string
    {
        $destination = $span->attributes[SpanAttribute::MESSAGING_DESTINATION_NAME] ?? null;
        $node = match (true) {
            in_array($span->name, ['send command', 'send event', 'send query'], true) => self::messageNodeOf((string) $destination, $graph),
            $span->name === 'send distributed' => 'distributed:' . DistributedBus::class,
            isset($span->attributes[SpanAttribute::PROJECTION_NAME]) && str_starts_with($span->name, 'process ') => 'projection:' . $span->attributes[SpanAttribute::PROJECTION_NAME],
            isset($span->attributes[SpanAttribute::CODE_FUNCTION_NAME]) => 'handler:' . $span->attributes[SpanAttribute::CODE_FUNCTION_NAME],
            str_starts_with($span->name, 'send ') => 'channel:' . substr($span->name, 5),
            str_starts_with($span->name, 'receive ') => 'channel:' . substr($span->name, 8),
            default => null,
        };

        return $node !== null && $graph->hasNode($node) ? $node : null;
    }

    private static function messageNodeOf(string $destination, MessageFlowGraph $graph): ?string
    {
        if ($graph->hasNode('message:' . $destination)) {
            return 'message:' . $destination;
        }
        foreach ($graph->nodes() as $node) {
            if (! $node->kind->isMessage()) {
                continue;
            }
            $subscription = (string) ($node->attributes['routingKey'] ?? '');
            if (($node->attributes['namedAs'] ?? null) === $destination
                || ($subscription !== '' && BusRoutingMap::globMatch($subscription, $destination))
                || ($subscription !== '' && class_exists($destination) && is_a($destination, $subscription, true))) {
                return $node->id;
            }
        }

        return null;
    }

    private static function hasPath(MessageFlowGraph $graph, string $from, string $to): bool
    {
        if ($graph->hasNode($to) && ($graph->node($to)->attributes['owner'] ?? null) === $from) {
            return true;
        }
        $targets = [$to => true];
        if (str_starts_with($to, 'channel:') && $graph->hasNode($to)) {
            foreach ($graph->edgesFrom($to) as $edge) {
                $targets[$edge->to] = true;
            }
        }
        foreach ($graph->nodes() as $node) {
            if (($node->attributes['owner'] ?? null) === $to) {
                $targets[$node->id] = true;
            }
        }
        $visited = [$from => true];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($targets[$current])) {
                return true;
            }
            foreach ($graph->edgesFrom($current) as $edge) {
                if (! isset($visited[$edge->to])) {
                    $visited[$edge->to] = true;
                    $queue[] = $edge->to;
                }
            }
        }

        return false;
    }
}
