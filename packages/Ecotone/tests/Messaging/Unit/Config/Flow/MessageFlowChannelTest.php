<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
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
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

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
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        $id = 'handler:' . $service::class . '::route';
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::INPUT, 'channel:optional.route', $id));
        self::assertSame([], $graph->edgesFrom($id));
    }
}
