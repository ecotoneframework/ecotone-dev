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

    public function test_the_ledger_holds_a_handler_with_its_module_builder_channels_and_target(): void
    {
        $ledger = $this->ledgerOf($this->orderingApplication());

        $handler = $ledger['handler:placeOrder'];
        self::assertStringEndsWith('Module', $handler['module']);
        self::assertSame('service activator', $handler['builder']);
        self::assertSame('placeOrder.target', $handler['inputChannel']);
        self::assertStringEndsWith('::place', $handler['target']);
        self::assertArrayNotHasKey('outputChannel', $handler);
    }

    public function test_the_ledger_holds_what_ecotone_derives_for_an_asynchronous_handler(): void
    {
        $ledger = $this->ledgerOf($this->orderingApplication());

        self::assertSame('whenOrderWasPlaced.target.execute', $ledger['handler:whenOrderWasPlaced']['inputChannel']);
        self::assertStringEndsWith('\AsynchronousModule', $ledger['channel:whenOrderWasPlaced.target.execute']['module']);
        self::assertSame('bridge', $ledger['handler:notifications']['builder']);
        self::assertSame('notifications', $ledger['handler:notifications']['inputChannel']);
        $routing = array_values(array_filter($ledger, fn (array $entry): bool => $entry['kind'] === 'handler' && ($entry['outputChannel'] ?? null) === 'notifications'));
        self::assertCount(1, $routing);
        self::assertSame('whenOrderWasPlaced.target', $routing[0]['inputChannel']);
        self::assertStringEndsWith('\AsynchronousModule', $routing[0]['module']);
    }

    public function test_the_ledger_holds_gateways_channels_interceptors_and_console_commands(): void
    {
        $ledger = $this->ledgerOf($this->orderingApplication());

        self::assertStringEndsWith('\MessagingGatewayModule', $ledger['gateway:' . CommandBus::class . '::send']['module']);
        self::assertTrue($ledger['channel:notifications']['pollable']);
        self::assertSame('ecotone:flow:dump', $ledger['console command:ecotone:flow:dump']['name']);
        self::assertStringEndsWith('\MessageFlowModule', $ledger['console command:ecotone:flow:dump']['module']);
        self::assertNotEmpty(array_filter($ledger, fn (array $entry): bool => $entry['kind'] === 'interceptor' && in_array($entry['position'], ['before', 'around', 'after', 'beforeSend'], true)));
    }

    public function test_the_ledger_holds_both_choices_of_a_service_chosen_by_the_licence(): void
    {
        $ledger = $this->ledgerOf($this->orderingApplication());

        $chosen = array_values(array_filter($ledger, fn (array $entry): bool => $entry['kind'] === 'service' && isset($entry['enterprise'])));
        self::assertNotEmpty($chosen);
        foreach ($chosen as $service) {
            self::assertNotSame($service['enterprise'], $service['openCore']);
            self::assertStringEndsWith('Module', $service['module']);
        }
    }

    public function test_the_ledger_holds_a_service_by_its_class_without_constructor_arguments(): void
    {
        $ledger = $this->ledgerOf($this->orderingApplication());

        $services = array_filter($ledger, fn (array $entry): bool => $entry['kind'] === 'service');
        self::assertNotEmpty($services);
        foreach ($services as $service) {
            self::assertSame([], array_diff(array_keys($service), ['module', 'kind', 'name', 'class', 'enterprise', 'openCore', 'aliasOf']));
        }
    }

    public function test_the_ledger_stays_the_same_when_the_application_is_built_again(): void
    {
        $first = $this->dumpOf($this->orderingApplication());

        self::assertSame(json_decode($this->dumpOf($this->orderingApplication()), true)['build']['ledger'], json_decode($first, true)['build']['ledger']);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', json_encode(json_decode($first, true)['build']));
    }

    public function test_a_handler_with_a_generated_endpoint_id_is_named_after_what_it_invokes(): void
    {
        $handler = new class () {
            #[CommandHandler('ticket.open')]
            public function open(): void
            {
            }
        };
        $ledger = $this->ledgerOf(EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE));

        $named = array_values(array_filter($ledger, fn (array $entry): bool => $entry['kind'] === 'handler' && str_ends_with($entry['target'] ?? '', '::open')));
        self::assertCount(1, $named);
        self::assertSame($named[0]['target'], $named[0]['name']);
    }

    public function test_the_build_is_left_out_of_the_dump_without_the_option(): void
    {
        $ecotone = $this->orderingApplication();
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json']);

        self::assertArrayNotHasKey('build', json_decode($ecotone->getInMemoryConsoleWriter()->getWrittenContent(), true));
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function ledgerOf(FlowTestSupport $ecotone): array
    {
        $ledger = [];
        foreach (json_decode($this->dumpOf($ecotone), true)['build']['ledger'] as $entry) {
            $ledger[$entry['kind'] . ':' . $entry['name']] = $entry;
        }

        return $ledger;
    }

    private function wiringOf(FlowTestSupport $ecotone): MessageFlowGraph
    {
        return MessageFlowGraph::fromJson($this->dumpOf($ecotone));
    }
}
