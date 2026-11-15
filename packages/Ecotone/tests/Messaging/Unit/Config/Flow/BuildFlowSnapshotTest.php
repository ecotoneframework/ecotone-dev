<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\Flow\Snapshot\BuildFlowConsoleCommands;
use Ecotone\Test\LicenceTesting;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * licence Apache-2.0
 * @internal
 */
final class BuildFlowSnapshotTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-build-flow-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->location)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->location, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->location);
    }

    public function test_recorded_snapshot_lists_the_traced_steps_in_parent_child_order(): void
    {
        $ecotone = $this->orderingApplication($this->orderingService());
        $ecotone->sendCommandWithRouting('order.place', []);
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => $this->location]);

        self::assertSame(
            [
                ['depth' => 0, 'name' => 'send command', 'destination' => 'order.place', 'kind' => 'Server', 'status' => 'Ok'],
                ['depth' => 1, 'name' => 'process class@anonymous::place', 'kind' => 'Server', 'status' => 'Ok'],
                ['depth' => 2, 'name' => 'send event', 'destination' => 'order.placed', 'kind' => 'Server', 'status' => 'Ok'],
                ['depth' => 3, 'name' => 'route whenOrderWasPlaced', 'destination' => 'whenOrderWasPlaced.target', 'kind' => 'Producer', 'status' => 'Ok'],
                ['depth' => 1, 'name' => 'send notifications', 'kind' => 'Producer', 'status' => 'Ok'],
                ['depth' => 2, 'name' => 'receive notifications', 'kind' => 'Consumer', 'status' => 'Ok'],
                ['depth' => 3, 'name' => 'process class@anonymous::whenPlaced', 'kind' => 'Server', 'status' => 'Ok'],
            ],
            $this->storedSnapshot('placing-an-order')['trace'],
        );
    }

    public function test_recorded_snapshot_leaves_out_generated_ids_timings_and_durations(): void
    {
        $ecotone = $this->orderingApplication($this->orderingService());
        $ecotone->sendCommandWithRouting('order.place', []);

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => $this->location]);
        $stored = (string) file_get_contents($this->location . '/placing-an-order.json');

        self::assertSame(['formatVersion', 'scenario', 'wiring', 'build', 'trace'], array_keys($this->storedSnapshot('placing-an-order')));
        self::assertSame(2, $this->storedSnapshot('placing-an-order')['formatVersion']);
        self::assertSame('Placing an order', $this->storedSnapshot('placing-an-order')['scenario']);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $stored);
        self::assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $stored);
        self::assertStringNotContainsString('\u0000', $stored);
        self::assertStringNotContainsString(__FILE__, $stored);
    }

    public function test_recorded_snapshot_holds_the_ledger_entry_and_the_chain_of_a_handler_of_the_application(): void
    {
        $ecotone = $this->orderingApplication($this->orderingService());

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => $this->location]);
        $build = $this->storedSnapshot('placing-an-order')['build'];
        $handler = array_values(array_filter($build['ledger'], fn (array $entry): bool => $entry['kind'] === 'handler' && $entry['name'] === 'placeOrder'));
        $chain = array_values(array_filter($build['chains'], fn (array $chain): bool => $chain['handler'] === 'placeOrder'));

        self::assertSame(['ledger', 'chains'], array_keys($build));
        self::assertCount(1, $handler);
        self::assertSame('class@anonymous#1::place', $handler[0]['target']);
        self::assertStringEndsWith('Module', $handler[0]['module']);
        self::assertCount(1, $chain);
        self::assertSame('event driven consumer of placeOrder.target', $chain[0]['layers'][0]);
        self::assertContains('handler class@anonymous#1::place(array $order, Ecotone\\Api\\Gateway\\EventBus $eventBus)', $chain[0]['layers']);
    }

    public function test_recorded_snapshot_stores_one_ledger_entry_and_one_chain_per_line_sorted(): void
    {
        $ecotone = $this->orderingApplication($this->orderingService());

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => $this->location]);
        $build = $this->storedSnapshot('placing-an-order')['build'];
        $stored = (string) file_get_contents($this->location . '/placing-an-order.json');

        $entries = array_map(fn (array $entry): string => $entry['kind'] . ':' . $entry['name'], $build['ledger']);
        $sorted = $entries;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $entries);
        $handlers = array_column($build['chains'], 'handler');
        $sortedHandlers = $handlers;
        sort($sortedHandlers, SORT_STRING);
        self::assertSame($sortedHandlers, $handlers);
        self::assertSame(count($build['ledger']), preg_match_all('/^            \{"module":/m', $stored));
        self::assertSame(count($build['chains']), preg_match_all('/^            \{"handler":/m', $stored));
    }

    public function test_recorded_snapshot_holds_the_build_wiring_of_the_application(): void
    {
        $ecotone = $this->orderingApplication($this->orderingService());

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => $this->location]);
        $wiring = MessageFlowGraph::fromArray($this->storedSnapshot('placing-an-order')['wiring']);

        self::assertTrue($wiring->node('channel:placeOrder.target')->attributes['build']);
        self::assertSame('class@anonymous#1', $wiring->node('handler:placeOrder')->attributes['class']);
        self::assertTrue($wiring->hasNode('handler:whenOrderWasPlaced'));
    }

    public function test_the_same_application_declared_elsewhere_is_recorded_as_the_same_bytes(): void
    {
        $first = $this->orderingApplication($this->orderingService());
        $second = $this->orderingApplication($this->orderingServiceDeclaredElsewhere());
        foreach ([$first, $second] as $ecotone) {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        }

        $first->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => $this->location . '/first']);
        $second->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => $this->location . '/second']);

        self::assertSame(file_get_contents($this->location . '/first/placing-an-order.json'), file_get_contents($this->location . '/second/placing-an-order.json'));
    }

    public function test_snapshot_nodes_and_arrows_are_stored_sorted(): void
    {
        $ecotone = $this->orderingApplication($this->orderingService());

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Placing an order', 'location' => $this->location]);
        $nodeIds = array_column($this->storedSnapshot('placing-an-order')['wiring']['nodes'], 'id');
        $sortedNodeIds = $nodeIds;
        sort($sortedNodeIds, SORT_STRING);

        self::assertSame($sortedNodeIds, $nodeIds);
        self::assertStringEndsWith("}\n", (string) file_get_contents($this->location . '/placing-an-order.json'));
    }

    public function test_recording_a_scenario_whose_name_gives_no_file_name_is_refused_with_the_way_out(): void
    {
        $ecotone = $this->orderingApplication($this->orderingService());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Scenario `???` cannot be recorded, because a snapshot is stored in a file named after the letters and digits of its scenario, and this name has none. Give the scenario a name with at least one letter or digit, for example `projection rebuilt asynchronously`.');

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => '???', 'location' => $this->location]);
    }

    private function orderingService(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }
        };
    }

    private function orderingServiceDeclaredElsewhere(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }
        };
    }

    private function orderingApplication(object $ordering): FlowTestSupport
    {
        $recorder = new InMemorySpanRecorder();

        return EcotoneLite::bootstrapFlowTesting(
            [$ordering::class, BuildFlowConsoleCommands::class],
            [$ordering, new BuildFlowConsoleCommands(), InMemorySpanRecorder::class => $recorder],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storedSnapshot(string $fileName): array
    {
        return json_decode((string) file_get_contents($this->location . '/' . $fileName . '.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
