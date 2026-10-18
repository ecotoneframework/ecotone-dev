<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowRoutingTest extends TestCase
{
    public function test_emitted_event_reaches_interface_subscriber(): void
    {
        $service = new class () {
            #[CommandHandler('routing.publish')]
            public function publish(EventBus $bus): void
            {
                $bus->publish(new RoutingEvent());
            }

            #[EventHandler]
            public function subscribe(RoutingEventContract $event): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, 'message:' . RoutingEvent::class, 'handler:' . $service::class . '::subscribe'));
        self::assertTrue($graph->node('message:' . RoutingEvent::class)->attributes['handled']);
    }

    public function test_emitted_event_reaches_parent_subscriber(): void
    {
        $service = new class () {
            #[CommandHandler('routing.publish')]
            public function publish(EventBus $bus): void
            {
                $bus->publish(new RoutingEvent());
            }

            #[EventHandler]
            public function subscribe(RoutingParentEvent $event): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, 'message:' . RoutingEvent::class, 'handler:' . $service::class . '::subscribe'));
        self::assertTrue($graph->node('message:' . RoutingEvent::class)->attributes['handled']);
    }

    public function test_emitted_event_reaches_object_subscriber(): void
    {
        $service = new class () {
            #[CommandHandler('routing.publish')]
            public function publish(EventBus $bus): void
            {
                $bus->publish(new RoutingEvent());
            }

            #[EventHandler]
            public function subscribe(object $event): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, 'message:' . RoutingEvent::class, 'handler:' . $service::class . '::subscribe'));
        self::assertTrue($graph->node('message:' . RoutingEvent::class)->attributes['handled']);
    }

    public function test_emitted_event_reaches_union_subscriber(): void
    {
        $service = new class () {
            #[CommandHandler('routing.publish')]
            public function publish(EventBus $bus): void
            {
                $bus->publish(new RoutingEvent());
            }

            #[EventHandler]
            public function subscribe(RoutingEvent|OtherRoutingEvent $event): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, 'message:' . RoutingEvent::class, 'handler:' . $service::class . '::subscribe'));
        self::assertTrue($graph->node('message:' . RoutingEvent::class)->attributes['handled']);
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, 'message:' . OtherRoutingEvent::class, 'handler:' . $service::class . '::subscribe'));
    }

    public function test_named_event_reaches_array_catch_all_subscriber(): void
    {
        $service = new class () {
            #[CommandHandler('routing.publish')]
            public function publish(EventBus $bus): void
            {
                $bus->publishWithRouting('routing.created', []);
            }

            #[EventHandler('*')]
            public function subscribe(array $event): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, 'message:routing.created', 'handler:' . $service::class . '::subscribe'));
        self::assertTrue($graph->node('message:routing.created')->attributes['handled']);
    }
}

interface RoutingEventContract
{
}

abstract class RoutingParentEvent
{
}

final class RoutingEvent extends RoutingParentEvent implements RoutingEventContract
{
}

final class OtherRoutingEvent
{
}
