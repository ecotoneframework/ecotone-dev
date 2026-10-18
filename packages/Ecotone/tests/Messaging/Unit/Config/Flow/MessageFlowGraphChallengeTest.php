<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowGraphChallengeTest extends TestCase
{
    public function test_empty_application_excludes_all_framework_nodes(): void
    {
        $graph = EcotoneLite::bootstrapFlowTesting(enabledModules: [])->getMessageFlowGraph();

        self::assertSame(['nodes' => [], 'edges' => []], $graph->toArray());
    }

    public function test_application_graph_excludes_framework_handlers_and_bus_gateways(): void
    {
        $service = new class () {
            #[CommandHandler('application.handle')]
            public function handle(EventBus $bus): void
            {
                $bus->publishWithRouting('application.event', []);
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$service::class], [$service], enabledModules: [])->getMessageFlowGraph();

        self::assertCount(1, $graph->nodesOfKind(FlowNodeKind::COMMAND_HANDLER));
        self::assertSame([], $graph->nodesOfKind(FlowNodeKind::INTERNAL_HANDLER, FlowNodeKind::GATEWAY));
        foreach ($graph->nodes() as $node) {
            self::assertStringStartsNotWith('Ecotone\\', $node->attributes['class'] ?? $node->attributes['interface'] ?? '');
        }
    }
}
