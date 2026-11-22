<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Flow\FlowEdge;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNode;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowFrameworkWiringTest extends TestCase
{
    public function test_dump_without_framework_stays_the_application_graph_byte_for_byte(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json']);
        $withoutOption = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'framework' => false]);

        self::assertSame($ecotone->getMessageFlowGraph()->toJson() . "\n", $withoutOption);
        self::assertSame($withoutOption . $withoutOption, $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        self::assertStringNotContainsString('"framework"', $withoutOption);
        self::assertStringNotContainsString(CommandBus::class, $withoutOption);
    }

    public function test_mermaid_dump_without_framework_stays_the_application_flowchart_byte_for_byte(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump');

        self::assertSame($ecotone->getMessageFlowGraph()->toMermaid(), $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        self::assertStringNotContainsString('classDef framework', $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_framework_dump_keeps_every_node_and_arrow_of_the_application_graph(): void
    {
        $ecotone = $this->orderingApplication();

        $frameworkGraph = $this->frameworkGraphOf($ecotone);

        foreach ($ecotone->getMessageFlowGraph()->nodes() as $node) {
            self::assertSame($node->toArray(), $frameworkGraph->node($node->id)->toArray());
        }
        $frameworkEdges = array_map(fn (FlowEdge $edge): array => $edge->toArray(), $frameworkGraph->edges());
        foreach ($ecotone->getMessageFlowGraph()->edges() as $edge) {
            self::assertContains($edge->toArray(), $frameworkEdges);
        }
    }

    public function test_framework_dump_connects_the_command_bus_through_its_routing_to_the_command_handler(): void
    {
        $frameworkGraph = $this->frameworkGraphOf($this->orderingApplication());

        $commandBus = $frameworkGraph->node('gateway:' . CommandBus::class . '::sendWithRouting');
        self::assertSame(FlowNodeKind::GATEWAY, $commandBus->kind);
        self::assertTrue($commandBus->attributes['framework']);
        self::assertSame(
            ['gateway', 'channel', 'internalHandler', 'channel', 'commandHandler'],
            $this->kindsOnShortestPath($frameworkGraph, $commandBus->id, 'handler:placeOrder'),
        );
        self::assertNotEmpty($frameworkGraph->edgesOfKind(FlowEdgeKind::ROUTES_TO, to: 'channel:placeOrder.target'));
    }

    public function test_framework_dump_connects_the_query_bus_and_the_event_bus_to_their_handlers(): void
    {
        $frameworkGraph = $this->frameworkGraphOf($this->orderingApplication());

        self::assertNotNull($this->kindsOnShortestPath($frameworkGraph, 'gateway:' . QueryBus::class . '::sendWithRouting', 'handler:orderStatus'));
        self::assertNotNull($this->kindsOnShortestPath($frameworkGraph, 'gateway:' . EventBus::class . '::publish', 'handler:whenOrderWasPlaced'));
    }

    public function test_framework_dump_routes_an_asynchronous_handler_through_its_channel_and_the_consumer_of_that_channel(): void
    {
        $frameworkGraph = $this->frameworkGraphOf($this->orderingApplication());

        self::assertNotNull($this->shortestPath($frameworkGraph, 'gateway:' . EventBus::class . '::publish', 'channel:whenOrderWasPlaced.target'));
        self::assertCount(1, $frameworkGraph->edgesOfKind(FlowEdgeKind::OUTPUT, $frameworkGraph->edgesFrom('channel:whenOrderWasPlaced.target')[0]->to, 'channel:notifications'));
        self::assertCount(1, $frameworkGraph->edgesOfKind(FlowEdgeKind::ROUTES_TO, 'endpoint:notifications', 'channel:whenOrderWasPlaced.target.execute'));
        self::assertCount(1, $frameworkGraph->edgesOfKind(FlowEdgeKind::INPUT, 'channel:whenOrderWasPlaced.target.execute', 'handler:whenOrderWasPlaced'));
        self::assertSame('polling', $frameworkGraph->edgesOfKind(FlowEdgeKind::INPUT, 'channel:notifications', 'endpoint:notifications')[0]->attributes['consumer']);
        self::assertSame('eventDriven', $frameworkGraph->edgesOfKind(FlowEdgeKind::INPUT, to: 'handler:placeOrder')[0]->attributes['consumer']);
    }

    public function test_framework_dump_shows_the_interceptors_the_framework_puts_around_the_command_bus(): void
    {
        $frameworkGraph = $this->frameworkGraphOf($this->orderingApplication());

        $interceptors = array_map(
            fn (FlowEdge $edge): FlowNode => $frameworkGraph->node($edge->to),
            $frameworkGraph->edgesOfKind(FlowEdgeKind::INTERCEPTED_BY, 'gateway:' . CommandBus::class . '::send'),
        );

        self::assertNotEmpty($interceptors);
        foreach ($interceptors as $interceptor) {
            self::assertSame(FlowNodeKind::INTERCEPTOR, $interceptor->kind);
            self::assertTrue($interceptor->attributes['framework']);
            self::assertContains($interceptor->attributes['position'], ['before', 'around', 'after']);
        }
    }

    public function test_framework_dump_marks_what_the_framework_registers_and_leaves_application_nodes_unmarked(): void
    {
        $ecotone = $this->orderingApplication();
        $frameworkGraph = $this->frameworkGraphOf($ecotone);
        $applicationNodeIds = array_map(fn (FlowNode $node): string => $node->id, $ecotone->getMessageFlowGraph()->nodes());

        self::assertSame([], array_values(array_map(
            fn (FlowNode $node): string => $node->id,
            array_filter($frameworkGraph->nodes(), fn (FlowNode $node): bool => (! in_array($node->id, $applicationNodeIds, true)) !== (($node->attributes['framework'] ?? false) === true)),
        )));
        self::assertTrue($frameworkGraph->node('channel:placeOrder.target')->attributes['framework']);
        self::assertArrayNotHasKey('framework', $frameworkGraph->node('channel:notifications')->attributes);
    }

    public function test_framework_dump_keeps_a_channel_the_application_registers_without_connecting_it_in_its_own_graph(): void
    {
        $ecotone = self::recordingApplication(
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('unconnected')],
        );

        $frameworkGraph = $this->frameworkGraphOf($ecotone);

        self::assertFalse($ecotone->getMessageFlowGraph()->hasNode('channel:unconnected'));
        self::assertArrayNotHasKey('framework', $frameworkGraph->node('channel:unconnected')->attributes);
        self::assertCount(1, $frameworkGraph->edgesOfKind(FlowEdgeKind::INPUT, 'channel:unconnected', 'endpoint:unconnected'));
    }

    public function test_framework_handlers_are_labelled_with_what_they_invoke_and_the_channel_they_consume(): void
    {
        $frameworkGraph = $this->frameworkGraphOf($this->orderingApplication());

        self::assertSame('ChainedMessageProcessor::process on ecotone.modelling.bus.command_by_name', $frameworkGraph->node('endpoint:ecotone.modelling.bus.command_by_name')->label);
        self::assertSame('Bridge::handle on notifications', $frameworkGraph->node('endpoint:notifications')->label);
        self::assertSame('RoutingSlipPrepender::transform on whenOrderWasPlaced.target', $frameworkGraph->node('endpoint:whenOrderWasPlaced.target')->label);
    }

    public function test_framework_node_ids_stay_the_same_when_the_application_is_built_again(): void
    {
        $handlerWithoutEndpointId = new class () {
            #[CommandHandler('stable.place')]
            public function place(): void
            {
            }

            #[Asynchronous('stable_async')]
            #[EventHandler('stable.placed', endpointId: 'whenStablePlaced')]
            public function whenPlaced(): void
            {
            }
        };
        $frameworkGraph = fn (): string => $this->buildDumpOf(self::recordingApplication(
            [$handlerWithoutEndpointId::class],
            [$handlerWithoutEndpointId],
            [SimpleMessageChannelBuilder::createQueueChannel('stable_async')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));

        $first = $frameworkGraph();

        self::assertSame($first, $frameworkGraph());
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $first);
        self::assertNotNull($this->kindsOnShortestPath(MessageFlowGraph::fromJson($first), 'gateway:' . CommandBus::class . '::sendWithRouting', 'handler:' . $handlerWithoutEndpointId::class . '::place'));
    }

    public function test_framework_mermaid_dump_draws_framework_nodes_with_their_own_style(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['framework' => true]);
        $mermaid = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();

        self::assertStringStartsWith("flowchart LR\n", $mermaid);
        self::assertStringContainsString('CommandBus::send', $mermaid);
        self::assertStringContainsString('    classDef framework stroke-dasharray:2 3,color:#57606a', $mermaid);
        self::assertMatchesRegularExpression('/^    class n\d+(,n\d+)* framework$/m', $mermaid);
    }

    private function orderingApplication(): FlowTestSupport
    {
        $ordering = new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(): void
            {
            }

            #[QueryHandler('order.status', endpointId: 'orderStatus')]
            public function status(): string
            {
                return 'placed';
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }
        };

        return self::recordingApplication(
            [$ordering::class],
            [$ordering],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function buildDumpOf(FlowTestSupport $ecotone): string
    {
        $alreadyWritten = strlen($ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'framework' => true]);

        return substr($ecotone->getInMemoryConsoleWriter()->getWrittenContent(), $alreadyWritten);
    }

    private function frameworkGraphOf(FlowTestSupport $ecotone): MessageFlowGraph
    {
        return MessageFlowGraph::fromJson($this->buildDumpOf($ecotone));
    }

    /**
     * @return list<string>|null
     */
    private function kindsOnShortestPath(MessageFlowGraph $graph, string $from, string $to): ?array
    {
        $path = $this->shortestPath($graph, $from, $to);

        return $path === null ? null : array_map(fn (string $id): string => $graph->node($id)->kind->value, $path);
    }

    /**
     * @return list<string>|null
     */
    private function shortestPath(MessageFlowGraph $graph, string $from, string $to): ?array
    {
        $paths = [$from => [$from]];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $to) {
                return $paths[$current];
            }
            foreach ($graph->edgesFrom($current) as $edge) {
                if ($edge->kind !== FlowEdgeKind::INTERCEPTED_BY && ! isset($paths[$edge->to])) {
                    $paths[$edge->to] = [...$paths[$current], $edge->to];
                    $queue[] = $edge->to;
                }
            }
        }

        return null;
    }

    /**
     * @param list<class-string> $classes
     * @param array<int|string, object> $services
     * @param list<object> $extensionObjects
     */
    private static function recordingApplication(array $classes = [], array $services = [], array $extensionObjects = [], array $enabledModules = [], ?string $licenceKey = null): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classes,
            [...$services],
            [...$extensionObjects, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: $enabledModules,
            licenceKey: $licenceKey,
        );
    }
}
