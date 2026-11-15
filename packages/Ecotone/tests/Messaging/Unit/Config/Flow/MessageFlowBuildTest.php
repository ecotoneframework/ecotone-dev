<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowBuildTest extends TestCase
{
    public function test_a_gateway_ecotone_registers_names_the_module_that_registered_it(): void
    {
        $wiring = $this->wiringOf($this->orderingApplication());

        self::assertStringEndsWith('\MessagingGatewayModule', $wiring->node('gateway:' . CommandBus::class . '::send')->attributes['registeredBy']);
    }

    public function test_a_channel_ecotone_derives_for_an_asynchronous_handler_names_the_module_that_made_the_handler_asynchronous(): void
    {
        $wiring = $this->wiringOf($this->orderingApplication());

        self::assertStringEndsWith('\AsynchronousModule', $wiring->node('channel:whenOrderWasPlaced.target.execute')->attributes['registeredBy']);
    }

    public function test_the_consumer_of_a_queue_channel_names_the_module_that_registered_the_channel(): void
    {
        $wiring = $this->wiringOf($this->orderingApplication());

        self::assertStringEndsWith('\BasicMessagingModule', $wiring->node('endpoint:notifications')->attributes['registeredBy']);
    }

    public function test_an_interceptor_ecotone_registers_names_the_module_that_registered_it(): void
    {
        $wiring = $this->wiringOf($this->orderingApplication());

        $registeredBy = [];
        foreach ($wiring->nodes() as $node) {
            if (str_starts_with($node->id, 'interceptor:')) {
                $registeredBy[] = $node->attributes['registeredBy'] ?? 'nothing';
            }
        }

        self::assertNotEmpty($registeredBy);
        self::assertNotContains('nothing', $registeredBy);
        self::assertNotContains('application', $registeredBy);
    }

    public function test_nodes_the_application_declares_carry_no_registering_module(): void
    {
        $ecotone = $this->orderingApplication();

        self::assertArrayNotHasKey('registeredBy', $this->wiringOf($this->orderingApplication())->node('handler:placeOrder')->attributes);
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json']);
        self::assertStringNotContainsString('registeredBy', $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    private function orderingApplication(): FlowTestSupport
    {
        $ordering = new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(): void
            {
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

    private function dumpOf(FlowTestSupport $ecotone): string
    {
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'internal' => true]);

        return $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
    }

    private function wiringOf(FlowTestSupport $ecotone): MessageFlowGraph
    {
        return MessageFlowGraph::fromJson($this->dumpOf($ecotone));
    }
}
