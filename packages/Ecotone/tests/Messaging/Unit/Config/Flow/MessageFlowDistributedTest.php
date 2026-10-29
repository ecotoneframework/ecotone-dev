<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowDistributedTest extends TestCase
{
    public function test_custom_distributed_reference_reaches_a_handler_that_publishes_locally(): void
    {
        $service = new class () {
            #[Distributed('partner.bus')]
            #[CommandHandler('remote.receive')]
            public function receive(array $payload, EventBus $bus): void
            {
                $bus->publish(new LocalEventForDistribution());
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        $handler = 'handler:' . $service::class . '::receive';
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::DISTRIBUTES, 'distributed:partner.bus', 'message:remote.receive'));
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, 'message:remote.receive', $handler));
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::EMITS, $handler, 'message:' . LocalEventForDistribution::class));
    }

    public function test_dynamic_distributed_destination_keeps_the_bus_edge_without_guessing_a_message(): void
    {
        $service = new class () {
            #[CommandHandler('remote.send')]
            public function send(array $payload, DistributedBus $bus): void
            {
                $bus->sendCommand($payload['service'], $payload['route'], '{}');
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        self::assertCount(1, $edges = $graph->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::send', 'distributed:' . DistributedBus::class));
        self::assertSame(['inferred' => true, 'via' => 'sendCommand'], $edges[0]->attributes);
        self::assertCount(1, $graph->nodesOfKind(FlowNodeKind::COMMAND));
    }

    public function test_distributed_command_handler_returning_a_value_replies_through_its_bus_and_a_void_one_does_not(): void
    {
        $service = new class () {
            #[Distributed]
            #[CommandHandler('tickets.hold')]
            public function hold(array $payload): array
            {
                return ['outcome' => 'held'];
            }

            #[Distributed]
            #[CommandHandler('tickets.release')]
            public function release(array $payload): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        $bus = 'distributed:' . DistributedBus::class;
        self::assertSame(
            [['via' => 'replyRoutingKey']],
            array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::hold', $bus), 'attributes'),
        );
        self::assertSame([], $graph->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::release', $bus));
    }

    public function test_send_through_a_helper_whose_routing_key_is_a_parameter_records_the_route_of_each_call_site(): void
    {
        $service = new class () {
            public const RELEASE = 'tickets.release';

            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $this->send($distributedBus, 'tickets.hold', ['seatId' => $event['seatId'], 'nested' => [1, 2]], replyRoutingKey: 'tickets.hold.result');
            }

            #[EventHandler('seat.released')]
            public function releaseSeat(array $event, DistributedBus $distributedBus): void
            {
                $this->sendRelease($distributedBus, $event['seatId']);
            }

            #[EventHandler('order.paid')]
            public function confirmPaidSeats(array $event, DistributedBus $distributedBus): void
            {
                $this->send(distributedBus: $distributedBus, routingKey: TicketRoute::CONFIRM->value, payload: $event, replyRoutingKey: 'tickets.confirm.result');
                $this->send($distributedBus, 'tickets.audit', $event, null);
            }

            private function sendRelease(DistributedBus $distributedBus, string $seatId): void
            {
                $this->send($distributedBus, self::RELEASE, ['seatId' => $seatId], replyRoutingKey: null);
            }

            private function send(DistributedBus $distributedBus, string $routingKey, array $payload, ?string $replyRoutingKey): void
            {
                $distributedBus->sendMessage(
                    targetServiceName: 'ticketing',
                    routingKey: $routingKey,
                    payload: json_encode($payload, JSON_THROW_ON_ERROR),
                    sourceMediaType: 'application/json',
                    metadata: ['seatId' => $payload['seatId'] ?? null],
                    replyRoutingKey: $replyRoutingKey,
                );
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        $bus = 'distributed:' . DistributedBus::class;
        $handler = fn (string $method) => 'handler:' . $service::class . '::' . $method;
        self::assertSame(
            [['inferred' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.hold', 'replyChannel' => 'tickets.hold.result']],
            array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, $handler('holdRequestedSeat'), $bus), 'attributes'),
        );
        self::assertSame(
            [['inferred' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.release']],
            array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, $handler('releaseSeat'), $bus), 'attributes'),
        );
        self::assertSame(
            [
                ['inferred' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.confirm', 'replyChannel' => 'tickets.confirm.result'],
                ['inferred' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.audit'],
            ],
            array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, $handler('confirmPaidSeats'), $bus), 'attributes'),
        );
    }

    public function test_routing_key_computed_at_runtime_stays_unresolved_instead_of_guessed(): void
    {
        $service = new class () {
            #[EventHandler('seat.requested')]
            public function concatenated(array $event, DistributedBus $distributedBus): void
            {
                $this->send($distributedBus, 'tickets.' . $event['action']);
            }

            #[EventHandler('seat.released')]
            public function readFromThePayload(array $event, DistributedBus $distributedBus): void
            {
                $this->send($distributedBus, $event['route']);
            }

            #[EventHandler('order.paid')]
            public function reassignedInTheHelper(array $event, DistributedBus $distributedBus): void
            {
                $this->sendPrefixed($distributedBus, 'hold');
            }

            #[EventHandler('order.failed')]
            public function loopVariable(array $event, DistributedBus $distributedBus): void
            {
                foreach ($event['routes'] as $route) {
                    $this->send($distributedBus, $route);
                }
            }

            private function sendPrefixed(DistributedBus $distributedBus, string $routingKey): void
            {
                $routingKey = 'tickets.' . $routingKey;
                $distributedBus->sendMessage('ticketing', $routingKey, '{}');
            }

            private function send(DistributedBus $distributedBus, string $routingKey): void
            {
                $distributedBus->sendMessage('ticketing', $routingKey, '{}');
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        foreach (['concatenated', 'readFromThePayload', 'reassignedInTheHelper', 'loopVariable'] as $method) {
            self::assertSame(
                [['inferred' => true, 'via' => 'sendMessage']],
                array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::' . $method, 'distributed:' . DistributedBus::class), 'attributes'),
            );
        }
    }

    public function test_helpers_calling_each_other_resolve_the_key_once_and_stop(): void
    {
        $service = new class () {
            #[EventHandler('seat.requested')]
            public function holdRequestedSeat(array $event, DistributedBus $distributedBus): void
            {
                $this->first($distributedBus, 'tickets.hold', 3);
            }

            private function first(DistributedBus $distributedBus, string $routingKey, int $attempts): void
            {
                if ($attempts > 0) {
                    $this->second($distributedBus, $routingKey, $attempts - 1);
                }
            }

            private function second(DistributedBus $distributedBus, string $routingKey, int $attempts): void
            {
                $distributedBus->sendMessage('ticketing', $routingKey, '{}');
                $this->first($distributedBus, $routingKey, $attempts);
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        self::assertSame(
            [['inferred' => true, 'via' => 'sendMessage', 'targetService' => 'ticketing', 'targetChannel' => 'tickets.hold']],
            array_column($graph->edgesOfKind(FlowEdgeKind::SENDS_TO, 'handler:' . $service::class . '::holdRequestedSeat', 'distributed:' . DistributedBus::class), 'attributes'),
        );
    }
}

final class LocalEventForDistribution
{
}

enum TicketRoute: string
{
    case CONFIRM = 'tickets.confirm';
}
