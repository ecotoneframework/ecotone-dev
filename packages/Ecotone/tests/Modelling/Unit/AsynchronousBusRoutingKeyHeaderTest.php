<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsynchronousBusRoutingKeyHeaderTest extends TestCase
{
    private const ROUTING_KEY_HEADER = 'ecotone_modelling_bus_routing_key';

    public function test_an_asynchronous_command_handler_sees_the_routing_key_it_was_sent_with(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->sendCommandWithRouting('order.place', ['orderId' => 1]);
        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['order.place'], $ecotone->sendQueryWithRouting('routingKeys.of', 'order.place'));
    }

    public function test_an_asynchronous_event_handler_sees_the_published_name_and_not_its_wildcard_pattern(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->publishEventWithRouting('order.shipped', ['orderId' => 1]);
        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['order.shipped'], $ecotone->sendQueryWithRouting('routingKeys.of', 'order.*'));
    }

    public function test_an_event_published_from_an_asynchronous_handler_does_not_carry_the_routing_key_of_its_parent(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->sendCommandWithRouting('order.place', ['orderId' => 1]);
        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame([null], $ecotone->sendQueryWithRouting('routingKeys.of', 'order.placed'));
    }

    public function test_a_command_handled_synchronously_carries_no_routing_key_header(): void
    {
        $ecotone = $this->bootstrap();

        $ecotone->sendCommandWithRouting('order.cancel', ['orderId' => 1]);

        self::assertSame([null], $ecotone->sendQueryWithRouting('routingKeys.of', 'order.cancel'));
    }

    private function bootstrap(): FlowTestSupport
    {
        $handlers = new class () {
            private array $routingKeys = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(array $order, #[Headers] array $headers, EventBus $eventBus): void
            {
                $this->routingKeys['order.place'][] = $headers['ecotone_modelling_bus_routing_key'] ?? null;
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[CommandHandler('order.cancel')]
            public function cancel(array $order, #[Headers] array $headers): void
            {
                $this->routingKeys['order.cancel'][] = $headers['ecotone_modelling_bus_routing_key'] ?? null;
            }

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event, #[Headers] array $headers): void
            {
                $this->routingKeys['order.placed'][] = $headers['ecotone_modelling_bus_routing_key'] ?? null;
            }

            #[Asynchronous('orders')]
            #[EventHandler('order.*', endpointId: 'order.any.endpoint')]
            public function whenAnyOrderEvent(array $event, #[Headers] array $headers): void
            {
                $this->routingKeys['order.*'][] = $headers['ecotone_modelling_bus_routing_key'] ?? null;
            }

            #[QueryHandler('routingKeys.of')]
            public function routingKeysOf(string $handler): array
            {
                return $this->routingKeys[$handler] ?? [];
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('orders')],
            enabledModules: [],
        );
    }
}
