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
final class MessageFlowInternalTest extends TestCase
{
    public function test_dump_without_internal_stays_the_application_graph_byte_for_byte(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json']);
        $withoutOption = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'internal' => false]);

        self::assertSame($ecotone->getMessageFlowGraph()->toJson() . "\n", $withoutOption);
        self::assertSame($withoutOption . $withoutOption, $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        self::assertStringNotContainsString('"internal"', $withoutOption);
        self::assertStringNotContainsString(CommandBus::class, $withoutOption);
    }

    public function test_mermaid_dump_without_internal_stays_the_application_flowchart_byte_for_byte(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump');

        self::assertSame($ecotone->getMessageFlowGraph()->toMermaid(), $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        self::assertStringNotContainsString('classDef internal', $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_internal_dump_keeps_every_node_and_arrow_of_the_application_graph(): void
    {
        $ecotone = $this->orderingApplication();

        $internal = $this->internalGraphOf($ecotone);

        foreach ($ecotone->getMessageFlowGraph()->nodes() as $node) {
            self::assertSame($node->toArray(), $internal->node($node->id)->toArray());
        }
        $internalEdges = array_map(fn (FlowEdge $edge): array => $edge->toArray(), $internal->edges());
        foreach ($ecotone->getMessageFlowGraph()->edges() as $edge) {
            self::assertContains($edge->toArray(), $internalEdges);
        }
    }

    public function test_internal_dump_connects_the_command_bus_through_its_routing_to_the_command_handler(): void
    {
        $internal = $this->internalGraphOf($this->orderingApplication());

        $commandBus = $internal->node('gateway:' . CommandBus::class . '::sendWithRouting');
        self::assertSame(FlowNodeKind::GATEWAY, $commandBus->kind);
        self::assertTrue($commandBus->attributes['internal']);
        self::assertSame(
            ['gateway', 'channel', 'internalHandler', 'channel', 'commandHandler'],
            $this->kindsOnShortestPath($internal, $commandBus->id, 'handler:placeOrder'),
        );
        self::assertNotEmpty($internal->edgesOfKind(FlowEdgeKind::ROUTES_TO, to: 'channel:placeOrder.target'));
    }

    public function test_internal_dump_connects_the_query_bus_and_the_event_bus_to_their_handlers(): void
    {
        $internal = $this->internalGraphOf($this->orderingApplication());

        self::assertNotNull($this->kindsOnShortestPath($internal, 'gateway:' . QueryBus::class . '::sendWithRouting', 'handler:orderStatus'));
        self::assertNotNull($this->kindsOnShortestPath($internal, 'gateway:' . EventBus::class . '::publish', 'handler:whenOrderWasPlaced'));
    }

    public function test_internal_dump_routes_an_asynchronous_handler_through_its_channel_and_the_consumer_of_that_channel(): void
    {
        $internal = $this->internalGraphOf($this->orderingApplication());

        self::assertNotNull($this->shortestPath($internal, 'gateway:' . EventBus::class . '::publish', 'channel:whenOrderWasPlaced.target'));
        self::assertCount(1, $internal->edgesOfKind(FlowEdgeKind::OUTPUT, $internal->edgesFrom('channel:whenOrderWasPlaced.target')[0]->to, 'channel:notifications'));
        self::assertCount(1, $internal->edgesOfKind(FlowEdgeKind::ROUTES_TO, 'endpoint:notifications', 'channel:whenOrderWasPlaced.target.execute'));
        self::assertCount(1, $internal->edgesOfKind(FlowEdgeKind::INPUT, 'channel:whenOrderWasPlaced.target.execute', 'handler:whenOrderWasPlaced'));
        self::assertSame('polling', $internal->edgesOfKind(FlowEdgeKind::INPUT, 'channel:notifications', 'endpoint:notifications')[0]->attributes['consumer']);
        self::assertSame('eventDriven', $internal->edgesOfKind(FlowEdgeKind::INPUT, to: 'handler:placeOrder')[0]->attributes['consumer']);
    }

    public function test_internal_dump_shows_the_interceptors_the_framework_puts_around_the_command_bus(): void
    {
        $internal = $this->internalGraphOf($this->orderingApplication());

        $interceptors = array_map(
            fn (FlowEdge $edge): FlowNode => $internal->node($edge->to),
            $internal->edgesOfKind(FlowEdgeKind::INTERCEPTED_BY, 'gateway:' . CommandBus::class . '::send'),
        );

        self::assertNotEmpty($interceptors);
        foreach ($interceptors as $interceptor) {
            self::assertSame(FlowNodeKind::INTERCEPTOR, $interceptor->kind);
            self::assertTrue($interceptor->attributes['internal']);
            self::assertContains($interceptor->attributes['position'], ['before', 'around', 'after']);
        }
    }

    public function test_internal_dump_marks_what_the_framework_registers_and_leaves_application_nodes_unmarked(): void
    {
        $ecotone = $this->orderingApplication();
        $internal = $this->internalGraphOf($ecotone);
        $applicationNodeIds = array_map(fn (FlowNode $node): string => $node->id, $ecotone->getMessageFlowGraph()->nodes());

        foreach ($internal->nodes() as $node) {
            self::assertSame(! in_array($node->id, $applicationNodeIds, true), ($node->attributes['internal'] ?? false) === true, $node->id);
        }
        self::assertTrue($internal->node('channel:placeOrder.target')->attributes['internal']);
        self::assertArrayNotHasKey('internal', $internal->node('channel:notifications')->attributes);
    }

    public function test_internal_dump_keeps_a_channel_the_application_registers_without_connecting_it_in_its_own_graph(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('unconnected')],
        );

        $internal = $this->internalGraphOf($ecotone);

        self::assertFalse($ecotone->getMessageFlowGraph()->hasNode('channel:unconnected'));
        self::assertArrayNotHasKey('internal', $internal->node('channel:unconnected')->attributes);
        self::assertCount(1, $internal->edgesOfKind(FlowEdgeKind::INPUT, 'channel:unconnected', 'endpoint:unconnected'));
    }

    public function test_internal_node_ids_stay_the_same_when_the_application_is_built_again(): void
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
        $build = fn (): string => $this->internalDumpOf(EcotoneLite::bootstrapFlowTesting(
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

    public function test_internal_mermaid_dump_draws_internal_nodes_with_their_own_style(): void
    {
        $ecotone = $this->orderingApplication();

        $ecotone->runConsoleCommand('ecotone:flow:dump', ['internal' => true]);
        $mermaid = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();

        self::assertStringStartsWith("flowchart LR\n", $mermaid);
        self::assertStringContainsString('CommandBus::send', $mermaid);
        self::assertStringContainsString('    classDef internal stroke-dasharray:2 3,color:#57606a', $mermaid);
        self::assertMatchesRegularExpression('/^    class n\d+(,n\d+)* internal$/m', $mermaid);
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

    private function internalDumpOf(FlowTestSupport $ecotone): string
    {
        $alreadyWritten = strlen($ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'internal' => true]);

        return substr($ecotone->getInMemoryConsoleWriter()->getWrittenContent(), $alreadyWritten);
    }

    private function internalGraphOf(FlowTestSupport $ecotone): MessageFlowGraph
    {
        return MessageFlowGraph::fromJson($this->internalDumpOf($ecotone));
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
