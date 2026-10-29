<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\AnnotationFinder\ConfigurationException as AnnotationConfigurationException;
use Ecotone\Api\Attribute\Before;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\SendsDistributedMessage;
use Ecotone\Api\Attribute\Transformer;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowDeclaredSendTest extends TestCase
{
    public function test_declared_send_records_the_route_that_inference_cannot_see(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: 'tickets.hold.result')]
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.release')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $distributedBus->sendMessage('ticketing', 'tickets.' . $event['action'], '{}');
            }
        };
        $graph = $this->graphOf($service);

        self::assertSame(
            [
                ['declared' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.hold', 'replyChannel' => 'tickets.hold.result'],
                ['declared' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.release'],
                ['inferred' => true, 'via' => 'sendMessage'],
            ],
            array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::holdRequestedSeat', 'distributed:' . DistributedBus::class), 'attributes'),
        );
    }

    public function test_route_both_declared_and_inferred_is_recorded_once(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $distributedBus->sendMessage('ticketing', 'tickets.hold', '{}');
            }
        };
        $graph = $this->graphOf($service);

        self::assertSame(
            [['declared' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.hold']],
            array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::holdRequestedSeat', 'distributed:' . DistributedBus::class), 'attributes'),
        );
    }

    public function test_declared_reply_key_contradicting_the_one_the_source_sends_is_refused_at_bootstrap(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: 'tickets.hold.result')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $distributedBus->sendMessage('ticketing', 'tickets.hold', '{}', replyRoutingKey: 'tickets.hold.answer');
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            "#[SendsDistributedMessage] on %s::holdRequestedSeat() declares the route to 'ticketing' with routing key 'tickets.hold' answered on 'tickets.hold.result', but the source of holdRequestedSeat() sends it with replyRoutingKey 'tickets.hold.answer'. One sent message is answered on one key, so only one of them can be right. Correct the replyRoutingKey of the attribute or of the sendMessage() call, so both name the key the target service answers on.",
            $service::class,
        ));

        $this->bootstrap($service);
    }

    public function test_declared_reply_key_on_a_route_the_source_sends_without_one_is_refused_at_bootstrap(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: 'tickets.hold.result')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $this->send($distributedBus, 'tickets.hold');
            }

            private function send(DistributedBus $distributedBus, string $routingKey): void
            {
                $distributedBus->sendMessage('ticketing', $routingKey, '{}');
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            "#[SendsDistributedMessage] on %s::holdRequestedSeat() declares the route to 'ticketing' with routing key 'tickets.hold' answered on 'tickets.hold.result', but the source of holdRequestedSeat() sends it with no reply. One sent message is answered on one key, so only one of them can be right. Correct the replyRoutingKey of the attribute or of the sendMessage() call, so both name the key the target service answers on.",
            $service::class,
        ));

        $this->bootstrap($service);
    }

    public function test_declared_reply_key_stands_when_the_source_computes_its_reply_key_at_runtime(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: 'tickets.hold.result')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $distributedBus->sendMessage('ticketing', 'tickets.hold', '{}', replyRoutingKey: $event['replyTo']);
            }
        };

        self::assertSame(
            [['declared' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.hold', 'replyChannel' => 'tickets.hold.result']],
            array_column($this->graphOf($service)->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::holdRequestedSeat', 'distributed:' . DistributedBus::class), 'attributes'),
        );
    }

    public function test_declared_send_is_recorded_on_a_transformer_and_an_interceptor(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold')]
            #[Transformer('seat.requests')]
            public function enrich(array $payload): array
            {
                return $payload;
            }

            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.audit')]
            #[Before(pointcut: CommandBus::class)]
            public function audit(): void
            {
            }
        };
        $graph = $this->graphOf($service);

        foreach (['handler:' . $service::class . '::enrich' => 'tickets.hold', 'interceptor:' . $service::class . '::audit' => 'tickets.audit'] as $sender => $routingKey) {
            self::assertSame(
                [['declared' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => $routingKey]],
                array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, $sender, 'distributed:' . DistributedBus::class), 'attributes'),
            );
        }
    }

    public function test_declared_reply_key_stands_when_the_source_spreads_the_reply_key_into_the_call(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: 'tickets.hold.result')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $distributedBus->sendMessage('ticketing', 'tickets.hold', ...$event['arguments']);
            }
        };

        self::assertSame(
            [['declared' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.hold', 'replyChannel' => 'tickets.hold.result']],
            array_column($this->graphOf($service)->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::holdRequestedSeat', 'distributed:' . DistributedBus::class), 'attributes'),
        );
    }

    public function test_declaration_naming_a_service_no_service_map_knows_is_refused(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketting', routingKey: 'tickets.hold')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            "#[SendsDistributedMessage] on %s::holdRequestedSeat() sends to service 'ticketting', but no DistributedServiceMap for %s maps that service, so DistributedBus::sendMessage() to it would fail at runtime. Mapped services: ticketing. Correct targetServiceName, or map the service in a #[ServiceContext] with DistributedServiceMap::initialize()->withCommandMapping('ticketting', '<channel name>').",
            $service::class,
            DistributedBus::class,
        ));

        $this->graphOf($service);
    }

    public function test_declaration_on_a_method_that_is_not_a_handler_is_refused_whatever_the_licence(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold')]
            public function send(DistributedBus $distributedBus, string $routingKey): void
            {
                $distributedBus->sendMessage('ticketing', $routingKey, '{}');
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            '#[SendsDistributedMessage] on %s::send() declares what a method sends, but send() is not one the message flow graph reads: it has none of #[CommandHandler], #[EventHandler], #[QueryHandler], #[InternalHandler], #[Router], #[Splitter], #[Transformer], #[Orchestrator], #[Scheduled], #[Presend], #[Before], #[Around] or #[After]. Move the attribute to the handler that calls send(), or make send() a handler.',
            $service::class,
        ));

        EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: []);
    }

    public function test_declaration_on_a_private_helper_is_refused_whatever_the_licence(): void
    {
        $service = new class () {
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $this->send($distributedBus, $event['route']);
            }

            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold')]
            private function send(DistributedBus $distributedBus, string $routingKey): void
            {
                $distributedBus->sendMessage('ticketing', $routingKey, '{}');
            }
        };

        $this->expectException(AnnotationConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            '#[SendsDistributedMessage] on %s::send() is on a method that is not public, but the attribute is read only from public message handler methods, so the route it declares would never be recorded. Put the attribute on the public handler method that calls send().',
            $service::class,
        ));

        EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: []);
    }

    public function test_the_same_declaration_twice_on_one_handler_is_refused(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold')]
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            "#[SendsDistributedMessage] on %s::holdRequestedSeat() declares the route to 'ticketing' with routing key 'tickets.hold' twice, so it cannot tell whether a second route was meant. Remove the repeated attribute, or correct the routing key of the one that names a different route.",
            $service::class,
        ));

        $this->graphOf($service);
    }

    public function test_one_route_declared_with_two_reply_keys_is_refused(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: 'tickets.hold.result')]
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: 'tickets.hold.answer')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            "#[SendsDistributedMessage] on %s::holdRequestedSeat() names two reply keys for the route to 'ticketing' with routing key 'tickets.hold': 'tickets.hold.result' and 'tickets.hold.answer'. One sent message is answered on one key, so only one of them can be right. Keep the declaration with the replyRoutingKey the target service answers on, and remove the other.",
            $service::class,
        ));

        $this->graphOf($service);
    }

    public function test_one_route_declared_with_and_without_a_reply_key_is_refused(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold')]
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: 'tickets.hold.result')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            "#[SendsDistributedMessage] on %s::holdRequestedSeat() names two reply keys for the route to 'ticketing' with routing key 'tickets.hold': no reply and 'tickets.hold.result'. One sent message is answered on one key, so only one of them can be right. Keep the declaration with the replyRoutingKey the target service answers on, and remove the other.",
            $service::class,
        ));

        $this->graphOf($service);
    }

    public function test_declaration_with_an_empty_routing_key_is_refused(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: '')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            '#[SendsDistributedMessage] on %s::holdRequestedSeat() has an empty routingKey, but a distributed message is routed by that key, so an empty one names no handler. Pass the routing key the target service handles.',
            $service::class,
        ));

        $this->graphOf($service);
    }

    public function test_declaration_with_an_empty_reply_key_is_refused(): void
    {
        $service = new class () {
            #[SendsDistributedMessage(targetServiceName: 'ticketing', routingKey: 'tickets.hold', replyRoutingKey: '')]
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            '#[SendsDistributedMessage] on %s::holdRequestedSeat() has an empty replyRoutingKey, but the reply is routed by that key, so an empty one names no handler. Pass the routing key the reply is handled on, or leave replyRoutingKey out when the target does not reply.',
            $service::class,
        ));

        $this->graphOf($service);
    }

    private function graphOf(object $service): MessageFlowGraph
    {
        return $this->bootstrap($service)->getMessageFlowGraph();
    }

    private function bootstrap(object $service): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$service::class],
            containerOrAvailableServices: [$service],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('ticketing_channel'),
                DistributedServiceMap::initialize()->withCommandMapping('ticketing', 'ticketing_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('storefront'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
