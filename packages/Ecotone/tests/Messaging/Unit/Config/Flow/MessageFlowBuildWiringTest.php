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
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowBuildWiringTest extends TestCase
{
    public function test_dump_without_build_stays_the_application_graph_byte_for_byte(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json']);
        $withoutOption = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'build' => false]);

        self::assertSame($ecotone->getMessageFlowGraph()->toJson() . "\n", $withoutOption);
        self::assertSame($withoutOption . $withoutOption, $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        self::assertStringNotContainsString('"build"', $withoutOption);
        self::assertStringNotContainsString(CommandBus::class, $withoutOption);
    }

    public function test_mermaid_dump_without_build_stays_the_application_flowchart_byte_for_byte(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump');

        self::assertSame($ecotone->getMessageFlowGraph()->toMermaid(), $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        self::assertStringNotContainsString('classDef build', $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_build_dump_keeps_every_node_and_arrow_of_the_application_graph(): void
    {
        $ecotone = $this->orderingApplication();

        $build = $this->buildGraphOf($ecotone);

        foreach ($ecotone->getMessageFlowGraph()->nodes() as $node) {
            self::assertSame($node->toArray(), $build->node($node->id)->toArray());
        }
        $buildEdges = array_map(fn (FlowEdge $edge): array => $edge->toArray(), $build->edges());
        foreach ($ecotone->getMessageFlowGraph()->edges() as $edge) {
            self::assertContains($edge->toArray(), $buildEdges);
        }
    }

    public function test_build_dump_connects_the_command_bus_through_its_routing_to_the_command_handler(): void
    {
        $build = $this->buildGraphOf($this->orderingApplication());

        $commandBus = $build->node('gateway:' . CommandBus::class . '::sendWithRouting');
        self::assertSame(FlowNodeKind::GATEWAY, $commandBus->kind);
        self::assertTrue($commandBus->attributes['build']);
        self::assertSame(
            ['gateway', 'channel', 'internalHandler', 'channel', 'commandHandler'],
            $this->kindsOnShortestPath($build, $commandBus->id, 'handler:placeOrder'),
        );
        self::assertNotEmpty($build->edgesOfKind(FlowEdgeKind::ROUTES_TO, to: 'channel:placeOrder.target'));
    }

    public function test_build_dump_connects_the_query_bus_and_the_event_bus_to_their_handlers(): void
    {
        $build = $this->buildGraphOf($this->orderingApplication());

        self::assertNotNull($this->kindsOnShortestPath($build, 'gateway:' . QueryBus::class . '::sendWithRouting', 'handler:orderStatus'));
        self::assertNotNull($this->kindsOnShortestPath($build, 'gateway:' . EventBus::class . '::publish', 'handler:whenOrderWasPlaced'));
    }

    public function test_build_dump_routes_an_asynchronous_handler_through_its_channel_and_the_consumer_of_that_channel(): void
    {
        $build = $this->buildGraphOf($this->orderingApplication());

        self::assertNotNull($this->shortestPath($build, 'gateway:' . EventBus::class . '::publish', 'channel:whenOrderWasPlaced.target'));
        self::assertCount(1, $build->edgesOfKind(FlowEdgeKind::OUTPUT, $build->edgesFrom('channel:whenOrderWasPlaced.target')[0]->to, 'channel:notifications'));
        self::assertCount(1, $build->edgesOfKind(FlowEdgeKind::ROUTES_TO, 'endpoint:notifications', 'channel:whenOrderWasPlaced.target.execute'));
        self::assertCount(1, $build->edgesOfKind(FlowEdgeKind::INPUT, 'channel:whenOrderWasPlaced.target.execute', 'handler:whenOrderWasPlaced'));
        self::assertSame('polling', $build->edgesOfKind(FlowEdgeKind::INPUT, 'channel:notifications', 'endpoint:notifications')[0]->attributes['consumer']);
        self::assertSame('eventDriven', $build->edgesOfKind(FlowEdgeKind::INPUT, to: 'handler:placeOrder')[0]->attributes['consumer']);
    }

    public function test_build_dump_shows_the_interceptors_the_framework_puts_around_the_command_bus(): void
    {
        $build = $this->buildGraphOf($this->orderingApplication());

        $interceptors = array_map(
            fn (FlowEdge $edge): FlowNode => $build->node($edge->to),
            $build->edgesOfKind(FlowEdgeKind::INTERCEPTED_BY, 'gateway:' . CommandBus::class . '::send'),
        );

        self::assertNotEmpty($interceptors);
        foreach ($interceptors as $interceptor) {
            self::assertSame(FlowNodeKind::INTERCEPTOR, $interceptor->kind);
            self::assertTrue($interceptor->attributes['build']);
            self::assertContains($interceptor->attributes['position'], ['before', 'around', 'after']);
        }
    }

    public function test_build_dump_marks_what_the_framework_registers_and_leaves_application_nodes_unmarked(): void
    {
        $ecotone = $this->orderingApplication();
        $build = $this->buildGraphOf($ecotone);
        $applicationNodeIds = array_map(fn (FlowNode $node): string => $node->id, $ecotone->getMessageFlowGraph()->nodes());

        self::assertSame([], array_values(array_map(
            fn (FlowNode $node): string => $node->id,
            array_filter($build->nodes(), fn (FlowNode $node): bool => (! in_array($node->id, $applicationNodeIds, true)) !== (($node->attributes['build'] ?? false) === true)),
        )));
        self::assertTrue($build->node('channel:placeOrder.target')->attributes['build']);
        self::assertArrayNotHasKey('build', $build->node('channel:notifications')->attributes);
    }

    public function test_build_dump_keeps_a_channel_the_application_registers_without_connecting_it_in_its_own_graph(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('unconnected')],
        );

        $build = $this->buildGraphOf($ecotone);

        self::assertFalse($ecotone->getMessageFlowGraph()->hasNode('channel:unconnected'));
        self::assertArrayNotHasKey('build', $build->node('channel:unconnected')->attributes);
        self::assertCount(1, $build->edgesOfKind(FlowEdgeKind::INPUT, 'channel:unconnected', 'endpoint:unconnected'));
    }

    public function test_build_handlers_are_labelled_with_what_they_invoke_and_the_channel_they_consume(): void
    {
        $build = $this->buildGraphOf($this->orderingApplication());

        self::assertSame('ChainedMessageProcessor::process on ecotone.modelling.bus.command_by_name', $build->node('endpoint:ecotone.modelling.bus.command_by_name')->label);
        self::assertSame('Bridge::handle on notifications', $build->node('endpoint:notifications')->label);
        self::assertSame('RoutingSlipPrepender::transform on whenOrderWasPlaced.target', $build->node('endpoint:whenOrderWasPlaced.target')->label);
    }

    public function test_build_node_ids_stay_the_same_when_the_application_is_built_again(): void
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
        $build = fn (): string => $this->buildDumpOf(EcotoneLite::bootstrapFlowTesting(
            [$handlerWithoutEndpointId::class],
            [$handlerWithoutEndpointId],
            [SimpleMessageChannelBuilder::createQueueChannel('stable_async')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));

        $first = $build();

        self::assertSame($first, $build());
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $first);
        self::assertNotNull($this->kindsOnShortestPath(MessageFlowGraph::fromJson($first), 'gateway:' . CommandBus::class . '::sendWithRouting', 'handler:' . $handlerWithoutEndpointId::class . '::place'));
    }

    public function test_build_mermaid_dump_draws_build_nodes_with_their_own_style(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['build' => true]);
        $mermaid = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();

        self::assertStringStartsWith("flowchart LR\n", $mermaid);
        self::assertStringContainsString('CommandBus::send', $mermaid);
        self::assertStringContainsString('    classDef build stroke-dasharray:2 3,color:#57606a', $mermaid);
        self::assertMatchesRegularExpression('/^    class n\d+(,n\d+)* build$/m', $mermaid);
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

        return EcotoneLite::bootstrapFlowTesting(
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
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'build' => true]);

        return substr($ecotone->getInMemoryConsoleWriter()->getWrittenContent(), $alreadyWritten);
    }

    private function buildGraphOf(FlowTestSupport $ecotone): MessageFlowGraph
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
}
