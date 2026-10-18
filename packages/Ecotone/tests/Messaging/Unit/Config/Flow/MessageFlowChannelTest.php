<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Poller;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\ExtensionObject\CombinedMessageChannel;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowChannelTest extends TestCase
{
    public function test_internal_channel_chain_reaches_a_router_with_multiple_destinations(): void
    {
        $service = new class () {
            #[CommandHandler('chain.start', outputChannelName: 'chain.first')]
            public function start(string $payload): string
            {
                return $payload;
            }

            #[InternalHandler('chain.first', outputChannelName: 'chain.second')]
            public function first(string $payload): string
            {
                return $payload;
            }

            #[InternalHandler('chain.second', outputChannelName: 'chain.route')]
            public function second(string $payload): string
            {
                return $payload;
            }

            #[Router('chain.route')]
            public function route(string $payload): array
            {
                return ['chain.left', 'chain.right'];
            }

            #[InternalHandler('chain.left')]
            public function left(string $payload): void
            {
            }

            #[InternalHandler('chain.right')]
            public function right(string $payload): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        foreach (['start' => 'first', 'first' => 'second', 'second' => 'route'] as $from => $to) {
            self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::OUTPUT, 'handler:' . $service::class . '::' . $from, 'channel:chain.' . $to));
            self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::INPUT, 'channel:chain.' . $to, 'handler:' . $service::class . '::' . $to));
        }
        foreach (['left', 'right'] as $destination) {
            self::assertCount(1, $edges = $graph->edgesOfKind(FlowEdgeKind::ROUTES_TO, 'handler:' . $service::class . '::route', 'channel:chain.' . $destination));
            self::assertTrue($edges[0]->isInferred());
        }
    }

    public function test_optional_router_with_no_destinations_keeps_its_input_edge(): void
    {
        $service = new class () {
            #[Router('optional.route', isResolutionRequired: false)]
            public function route(string $payload): array
            {
                return [];
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        $id = 'handler:' . $service::class . '::route';
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::INPUT, 'channel:optional.route', $id));
        self::assertSame([], $graph->edgesFrom($id));
    }
    public function test_async_channel_list_shows_the_effective_channels(): void
    {
        $service = new class () {
            #[Asynchronous(['async.first', 'async.second'])]
            #[CommandHandler('async.trigger', endpointId: 'async.handler')]
            public function handle(string $payload): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$service::class],
            containerOrAvailableServices: [$service],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('async.first'),
                SimpleMessageChannelBuilder::createQueueChannel('async.second'),

            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->getMessageFlowGraph();

        $edges = $graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS, to: 'handler:' . $service::class . '::handle');
        self::assertEqualsCanonicalizing(['channel:async.first', 'channel:async.second'], array_column($edges, 'from'));
        foreach ($edges as $edge) {
            self::assertTrue($graph->node($edge->from)->attributes['pollable']);
        }
    }

    public function test_async_combined_channel_shows_the_effective_channels(): void
    {
        $service = new class () {
            #[Asynchronous('async.combined')]
            #[CommandHandler('async.trigger', endpointId: 'async.handler')]
            public function handle(string $payload): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$service::class],
            containerOrAvailableServices: [$service],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('async.first'),
                SimpleMessageChannelBuilder::createQueueChannel('async.second'),
                CombinedMessageChannel::create('async.combined', ['async.first', 'async.second']),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->getMessageFlowGraph();

        $edges = $graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS, to: 'handler:' . $service::class . '::handle');
        self::assertEqualsCanonicalizing(['channel:async.first', 'channel:async.second'], array_column($edges, 'from'));
        foreach ($edges as $edge) {
            self::assertTrue($graph->node($edge->from)->attributes['pollable']);
        }
    }

    public function test_async_method_override_shows_the_effective_channels(): void
    {
        $service = new #[Asynchronous('async.first')] class () {
            #[Asynchronous('async.second')]
            #[CommandHandler('async.trigger', endpointId: 'async.handler')]
            public function handle(string $payload): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$service::class],
            containerOrAvailableServices: [$service],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('async.first'),
                SimpleMessageChannelBuilder::createQueueChannel('async.second'),

            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->getMessageFlowGraph();

        $edges = $graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS, to: 'handler:' . $service::class . '::handle');
        self::assertEqualsCanonicalizing(['channel:async.second'], array_column($edges, 'from'));
        foreach ($edges as $edge) {
            self::assertTrue($graph->node($edge->from)->attributes['pollable']);
        }
    }

    public function test_async_internal_handler_shows_the_effective_channels(): void
    {
        $service = new class () {
            #[Asynchronous('async.first')]
            #[InternalHandler('async.input', endpointId: 'async.handler')]
            public function handle(string $payload): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$service::class],
            containerOrAvailableServices: [$service],
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('async.first'),
                SimpleMessageChannelBuilder::createQueueChannel('async.second'),

            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->getMessageFlowGraph();

        $edges = $graph->edgesOfKind(FlowEdgeKind::ASYNCHRONOUS, to: 'handler:' . $service::class . '::handle');
        self::assertEqualsCanonicalizing(['channel:async.first'], array_column($edges, 'from'));
        foreach ($edges as $edge) {
            self::assertTrue($graph->node($edge->from)->attributes['pollable']);
        }
    }

    public function test_scheduled_poller_feeds_an_internal_handler_requiring_a_reply(): void
    {
        $service = new class () {
            #[Scheduled('scheduled.ticks', endpointId: 'scheduled.clock')]
            #[Poller(fixedRateInMilliseconds: 1000)]
            public function tick(): string
            {
                return 'tick';
            }

            #[InternalHandler('scheduled.ticks', outputChannelName: 'scheduled.done', requiresReply: true)]
            public function process(string $payload): string
            {
                return $payload;
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        $scheduled = 'scheduled:' . $service::class . '::tick';
        $handler = 'handler:' . $service::class . '::process';
        self::assertSame('scheduled.clock', $graph->node($scheduled)->attributes['endpointId']);
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::SENDS_TO, $scheduled, 'channel:scheduled.ticks'));
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::INPUT, 'channel:scheduled.ticks', $handler));
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::OUTPUT, $handler, 'channel:scheduled.done'));
    }

    public function test_scheduled_method_publishing_an_event_keeps_its_emission(): void
    {
        $bus = EcotoneLite::bootstrapFlowTesting(enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getGateway(\Ecotone\Api\Gateway\EventBus::class);
        $service = new class ($bus) {
            public function __construct(private \Ecotone\Api\Gateway\EventBus $bus)
            {
            }

            #[Scheduled(endpointId: 'scheduled.publisher')]
            public function publish(): void
            {
                $this->bus->publishWithRouting('scheduled.event', []);
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE)->getMessageFlowGraph();

        self::assertCount(1, $edges = $graph->edgesOfKind(FlowEdgeKind::EMITS, 'scheduled:' . $service::class . '::publish', 'message:scheduled.event'));
        self::assertSame(['inferred' => true, 'via' => 'publishWithRouting'], $edges[0]->attributes);
    }

    public function test_router_reaches_a_configured_queue_without_a_local_handler(): void
    {
        $service = new class () {
            #[Router('external.route')]
            public function route(string $payload): array
            {
                return ['external.delivery'];
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$service::class],
            containerOrAvailableServices: [$service],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('external.delivery')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->getMessageFlowGraph();

        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTES_TO, 'handler:' . $service::class . '::route', 'channel:external.delivery'));
        self::assertTrue($graph->node('channel:external.delivery')->attributes['pollable']);
    }

    public function test_configured_queue_nothing_connects_to_is_left_out_of_the_graph(): void
    {
        $service = new class () {
            #[CommandHandler('unused.queue.trigger')]
            public function handle(): void
            {
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$service::class],
            containerOrAvailableServices: [$service],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('unused.queue')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->getMessageFlowGraph();

        self::assertFalse($graph->hasNode('channel:unused.queue'));
    }
}
