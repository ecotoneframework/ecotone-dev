<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Modelling\WithEvents;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowEmissionTest extends TestCase
{
    public function test_event_static_factory_is_shown_as_published(): void
    {
        $service = new class () {
            #[CommandHandler('factory.external')]
            public function handle(array $payload, EventBus $bus): void
            {
                $bus->publish(EmissionEvent::create());
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $edges = $graph->edgesOfKind(FlowEdgeKind::EMITS, 'handler:' . $service::class . '::handle', 'message:' . EmissionEvent::class));
        self::assertSame(['inferred' => true, 'via' => 'publish'], $edges[0]->attributes);
    }

    public function test_aggregate_static_helper_event_is_shown_as_recorded_on_the_fresh_instance(): void
    {
        $aggregate = new #[Aggregate] class () {
            use WithEvents;

            #[Identifier]
            private string $id = 'order';

            #[CommandHandler('factory.local')]
            public static function create(): static
            {
                $order = new static();
                $order->recordThat(self::placed());

                return $order;
            }

            private static function placed(): EmissionEvent
            {
                return new EmissionEvent();
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$aggregate::class], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $edges = $graph->edgesOfKind(FlowEdgeKind::EMITS, 'handler:' . $aggregate::class . '::create', 'message:' . EmissionEvent::class));
        self::assertSame(['inferred' => true, 'via' => 'recordThat'], $edges[0]->attributes);
    }
    public function test_event_assigned_before_publication_is_shown_as_published(): void
    {
        $service = new class () {
            #[CommandHandler('event.variable')]
            public function handle(array $payload, EventBus $bus): void
            {
                $event = new EmissionEvent();
                $bus->publish($event);
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $edges = $graph->edgesOfKind(FlowEdgeKind::EMITS, 'handler:' . $service::class . '::handle', 'message:' . EmissionEvent::class));
        self::assertSame('publish', $edges[0]->attributes['via']);
    }

    public function test_event_returned_from_private_helper_is_shown_as_published(): void
    {
        $service = new class () {
            #[CommandHandler('event.helper')]
            public function handle(array $payload, EventBus $bus): void
            {
                $bus->publish($this->event());
            }

            private function event(): EmissionEvent
            {
                return new EmissionEvent();
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $edges = $graph->edgesOfKind(FlowEdgeKind::EMITS, 'handler:' . $service::class . '::handle', 'message:' . EmissionEvent::class));
        self::assertSame('publish', $edges[0]->attributes['via']);
    }

}

final class EmissionEvent
{
    public static function create(): static
    {
        return new static();
    }
}
