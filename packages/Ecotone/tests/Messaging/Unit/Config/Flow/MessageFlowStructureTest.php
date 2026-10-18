<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\FlowEdgeKind;
use Ecotone\Api\Messaging\Flow\FlowNodeKind;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowStructureTest extends TestCase
{
    public function test_saga_command_handler_shows_its_owner_and_emitted_command(): void
    {
        $saga = new #[Saga] class () {
            #[Identifier]
            private string $id = 'order';

            #[CommandHandler('saga.command')]
            public function handle(CommandBus $bus): void
            {
                $bus->sendWithRouting('saga.next', []);
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$saga::class], enabledModules: [])->getMessageFlowGraph();

        $id = 'handler:' . $saga::class . '::handle';
        self::assertSame(FlowNodeKind::SAGA, $graph->node('saga:' . $saga::class)->kind);
        self::assertSame('saga:' . $saga::class, $graph->node($id)->attributes['owner']);
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::EMITS, $id, 'message:saga.next'));
    }

    public function test_saga_event_handler_shows_its_owner_and_emitted_command(): void
    {
        $saga = new #[Saga] class () {
            #[Identifier]
            private string $id = 'order';

            #[EventHandler('saga.event')]
            public function handle(CommandBus $bus): void
            {
                $bus->sendWithRouting('saga.next', []);
            }
        };
        $graph = EcotoneLite::bootstrapFlowTesting([$saga::class], enabledModules: [])->getMessageFlowGraph();

        $id = 'handler:' . $saga::class . '::handle';
        self::assertSame('saga:' . $saga::class, $graph->node($id)->attributes['owner']);
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::ROUTED_TO, 'message:saga.event', $id));
        self::assertCount(1, $graph->edgesOfKind(FlowEdgeKind::EMITS, $id, 'message:saga.next'));
    }
}
