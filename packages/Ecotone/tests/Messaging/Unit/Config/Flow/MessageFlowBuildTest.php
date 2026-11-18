<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\After;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Before;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
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
        $ledger = $this->ledgerOf(self::recordingApplication([$handler::class], [$handler], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE));

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

    public function test_the_chain_of_a_command_handler_starts_at_its_consumer_and_ends_with_where_the_result_goes(): void
    {
        $chain = $this->chainsOf($this->orderingApplication())['placeOrder'];

        self::assertSame('event driven consumer of placeOrder.target', $chain[0]);
        self::assertStringStartsWith('handler ', $chain[count($chain) - 4]);
        self::assertStringEndsWith('::place()', $chain[count($chain) - 4]);
        self::assertSame('routing slip: the result goes to the next channel on it', $chain[count($chain) - 3]);
        self::assertStringStartsWith('part completion ', $chain[count($chain) - 2]);
        self::assertSame('reply channel of the message', $chain[count($chain) - 1]);
    }

    public function test_the_chain_of_a_queue_channel_consumer_names_the_polling_consumer_and_the_interceptors_around_it(): void
    {
        $chain = $this->chainsOf($this->orderingApplication())['notifications'];

        self::assertSame('polling consumer of notifications', $chain[0]);
        self::assertNotEmpty(array_filter($chain, fn (string $layer): bool => str_starts_with($layer, 'around interceptor ') && str_ends_with($layer, '\AcknowledgeConfirmationInterceptor::ack')));
        self::assertNotEmpty(array_filter($chain, fn (string $layer): bool => str_starts_with($layer, 'handler ') && str_contains($layer, '\Bridge::handle(')));
    }

    public function test_the_chain_names_the_handler_with_its_parameter_types(): void
    {
        $handler = new class () {
            #[CommandHandler(endpointId: 'openTicket')]
            public function open(OpenTicketForBuild $command, CommandBus $commandBus): void
            {
            }
        };

        $chain = $this->chainsOf(self::recordingApplication([$handler::class], [$handler], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE))['openTicket'];

        self::assertNotEmpty(array_filter($chain, fn (string $layer): bool => str_ends_with($layer, '::open(' . OpenTicketForBuild::class . ' $command, ' . CommandBus::class . ' $commandBus)')));
    }

    public function test_the_chain_puts_interceptors_of_the_application_in_the_order_they_run(): void
    {
        $handler = new class () {
            #[CommandHandler('ticket.open', endpointId: 'openTicket')]
            public function open(): void
            {
            }

            #[Before(pointcut: CommandHandler::class)]
            public function check(): void
            {
            }

            #[Around(pointcut: CommandHandler::class)]
            public function measure(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
            }

            #[After(pointcut: CommandHandler::class)]
            public function audit(): void
            {
            }
        };

        $chain = $this->chainsOf(self::recordingApplication([$handler::class], [$handler], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE))['openTicket'];
        $positionOf = function (string $start, string $end) use ($chain): int {
            $positions = array_keys(array_filter($chain, fn (string $layer): bool => str_starts_with($layer, $start) && str_ends_with($layer, $end)));
            self::assertCount(1, $positions, $start . '...' . $end);

            return $positions[0];
        };

        self::assertLessThan($positionOf('around interceptor ', '::measure'), $positionOf('before interceptor ', '::check()'));
        self::assertLessThan($positionOf('handler ', '::open()'), $positionOf('around interceptor ', '::measure'));
        self::assertLessThan($positionOf('after interceptor ', '::audit()'), $positionOf('handler ', '::open()'));
    }

    public function test_the_chain_of_a_splitter_names_the_splitter_and_its_output_channel(): void
    {
        $chain = $this->chainsOf($this->splittingApplication())['splitOrder'];

        self::assertNotEmpty(array_filter($chain, fn (string $layer): bool => str_starts_with($layer, 'splitter ') && str_ends_with($layer, '::split()')));
        self::assertEmpty(array_filter($chain, fn (string $layer): bool => str_starts_with($layer, 'handler ')));
        self::assertSame('each split message to output channel order.lines', $chain[count($chain) - 1]);
    }

    public function test_the_chain_of_a_router_names_the_router(): void
    {
        $chain = $this->chainsOf($this->splittingApplication())['routeOrder'];

        self::assertStringStartsWith('router ', $chain[1]);
        self::assertStringEndsWith('::route()', $chain[1]);
        self::assertSame('the channel the router resolves', $chain[2]);
    }

    public function test_the_chain_of_a_handler_with_an_output_channel_ends_at_that_channel(): void
    {
        $chain = $this->chainsOf($this->splittingApplication())['handleLine'];

        self::assertContains('output channel order.done', $chain);
        self::assertNotContains('reply channel of the message', $chain);
    }

    public function test_the_chain_of_a_gathered_handler_names_the_gatherer_before_the_handler(): void
    {
        $chain = $this->chainsOf($this->splittingApplication())['whenAllLinesHandled'];

        $gatherer = array_keys(array_filter($chain, fn (string $layer): bool => str_starts_with($layer, 'gatherer ')));
        $handler = array_keys(array_filter($chain, fn (string $layer): bool => str_starts_with($layer, 'handler ')));
        self::assertCount(1, $gatherer);
        self::assertStringContainsString('Enterprise', $chain[$gatherer[0]]);
        self::assertLessThan($handler[0], $gatherer[0]);
    }

    public function test_every_handler_of_the_ledger_has_a_chain_and_no_chain_holds_a_generated_id(): void
    {
        $build = json_decode($this->dumpOf($this->splittingApplication()), true)['build'];

        $handlers = array_column(array_filter($build['ledger'], fn (array $entry): bool => $entry['kind'] === 'handler'), 'name');
        sort($handlers);
        $withChain = array_keys($build['chains']);
        sort($withChain);
        self::assertSame($handlers, $withChain);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{64}/', json_encode($build['chains']));
        self::assertSame($build['chains'], json_decode($this->dumpOf($this->splittingApplication()), true)['build']['chains']);
    }

    public function test_no_layer_of_these_applications_is_left_unrecognised(): void
    {
        self::assertStringNotContainsString('unrecognised', json_encode($this->chainsOf($this->splittingApplication())));
        self::assertStringNotContainsString('unrecognised', json_encode($this->chainsOf($this->orderingApplication())));
    }

    private function splittingApplication(): FlowTestSupport
    {
        $ordering = new class () {
            #[Splitter(inputChannelName: 'order.split', endpointId: 'splitOrder', outputChannelName: 'order.lines', gatherChannelName: 'order.gathered')]
            public function split(): array
            {
                return [1, 2];
            }

            #[InternalHandler(inputChannelName: 'order.lines', endpointId: 'handleLine', outputChannelName: 'order.done')]
            public function handle(): int
            {
                return 1;
            }

            #[InternalHandler(inputChannelName: 'order.done', endpointId: 'whenLineDone')]
            public function done(): void
            {
            }

            #[Gathered]
            #[Asynchronous('gathering')]
            #[InternalHandler(inputChannelName: 'order.gathered', endpointId: 'whenAllLinesHandled')]
            public function gathered(GatheredParts $parts): void
            {
            }

            #[Router('order.route', endpointId: 'routeOrder')]
            public function route(): string
            {
                return 'order.split';
            }
        };

        return self::recordingApplication([$ordering::class], [$ordering], [SimpleMessageChannelBuilder::createQueueChannel('gathering')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
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

        return self::recordingApplication(
            [$ordering::class],
            [$ordering],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function dumpOf(FlowTestSupport $ecotone): string
    {
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'build' => true]);

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

    /**
     * @return array<string, list<string>>
     */
    private function chainsOf(FlowTestSupport $ecotone): array
    {
        return json_decode($this->dumpOf($ecotone), true)['build']['chains'];
    }

    private function wiringOf(FlowTestSupport $ecotone): MessageFlowGraph
    {
        return MessageFlowGraph::fromJson($this->dumpOf($ecotone));
    }

    /**
     * @param list<class-string> $classes
     * @param array<int|string, object> $services
     * @param list<object> $extensionObjects
     */
    private static function recordingApplication(array $classes = [], array $services = [], array $extensionObjects = [], array $enabledModules = [], ?string $licenceKey = null): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            $classes,
            [...$services, InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [...$extensionObjects, TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel(TracingLevel::Framework)],
            enabledModules: $enabledModules,
            licenceKey: $licenceKey,
        );
    }
}

final class OpenTicketForBuild
{
}
