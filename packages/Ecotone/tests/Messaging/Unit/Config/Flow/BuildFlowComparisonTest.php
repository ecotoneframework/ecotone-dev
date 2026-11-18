<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Before;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Lite\Test\MessagingTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
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
final class BuildFlowComparisonTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-build-flow-comparison-' . bin2hex(random_bytes(6));
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

    public function test_only_scenarios_that_differ_appear_in_the_comparison(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAudit());
        $this->record('Unchanged ordering', 'before', $this->ordering());
        $this->record('Unchanged ordering', 'after', $this->ordering());

        $comparison = $this->comparison();

        self::assertSame(2, $comparison['scenariosCompared']);
        self::assertSame([['Placing an order']], array_column($comparison['groups'], 'scenarios'));
        self::assertSame('changed', $comparison['groups'][0]['state']);
    }

    public function test_identical_snapshots_compare_to_no_groups(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());

        self::assertSame([], $this->comparison()['groups']);
    }

    public function test_wiring_is_cut_to_the_changed_nodes_and_arrows_with_their_direct_neighbours(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAudit());

        $group = $this->comparison()['groups'][0];

        self::assertSame(['channel:audit.target', 'handler:audit'], array_column($group['wiring']['added']['nodes'], 'id'));
        self::assertCount(1, $group['wiringParts']);
        $shown = $group['wiringParts'][0]['nodes'];
        self::assertContains('handler:audit', $shown);
        self::assertContains('channel:audit.target', $shown);
        self::assertContains('message:order.placed', $shown);
        self::assertContains('endpoint:ecotone.modelling.bus.event_by_name', $shown);
        self::assertNotContains('handler:placeOrder', $shown);
        self::assertNotContains('channel:notifications', $shown);
        self::assertNotContains('gateway:Ecotone\Api\Gateway\CommandBus::send', $shown);
        self::assertLessThanOrEqual(15, count($shown));
    }

    public function test_changed_parts_that_are_not_connected_share_one_part_while_they_fit_fifteen_nodes(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAuditAndStatus());

        $group = $this->comparison()['groups'][0];

        self::assertCount(1, $group['wiringParts']);
        self::assertContains('handler:audit', $group['wiringParts'][0]['nodes']);
        self::assertContains('handler:orderStatus', $group['wiringParts'][0]['nodes']);
        self::assertLessThanOrEqual(15, count($group['wiringParts'][0]['nodes']));
    }

    public function test_a_change_larger_than_fifteen_nodes_is_split_into_parts_and_the_rest_is_counted(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithManySubscribers());

        $group = $this->comparison()['groups'][0];
        $shown = array_merge(...array_column($group['wiringParts'], 'nodes'));

        self::assertGreaterThan(15, count($group['wiring']['added']['nodes']));
        self::assertGreaterThan(1, count($group['wiringParts']));
        foreach ($group['wiringParts'] as $part) {
            self::assertLessThanOrEqual(15, count($part['nodes']));
        }
        foreach (array_column($group['wiring']['added']['nodes'], 'id') as $addedNodeId) {
            self::assertContains($addedNodeId, $shown);
        }
    }

    public function test_unchanged_neighbours_beyond_fifteen_nodes_are_counted_instead_of_shown(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAnInterceptorAroundEveryBus());

        $group = $this->comparison()['groups'][0];

        self::assertSame(['interceptor:class@anonymous#1::stamp'], array_column($group['wiring']['added']['nodes'], 'id'));
        self::assertCount(1, $group['wiringParts']);
        self::assertCount(15, $group['wiringParts'][0]['nodes']);
        self::assertContains('interceptor:class@anonymous#1::stamp', $group['wiringParts'][0]['nodes']);
        self::assertSame(count($group['wiring']['added']['edges']) - 14, $group['wiringParts'][0]['unchangedNeighboursLeftOut']);
        self::assertGreaterThan(0, $group['wiringParts'][0]['unchangedNeighboursLeftOut']);
    }

    public function test_a_long_trace_is_cut_to_the_changed_steps_with_one_step_of_context_on_each_side(): void
    {
        $this->record('Placing three orders', 'before', $this->ordering(), times: 3);
        $this->record('Placing three orders', 'after', $this->orderingWithAudit(), times: 3);

        $trace = $this->comparison()['groups'][0]['trace'];

        self::assertSame(
            ['left out', 'unchanged', 'added', 'added', 'unchanged', 'left out', 'unchanged', 'added', 'added', 'unchanged', 'left out', 'unchanged', 'added', 'added', 'unchanged', 'left out'],
            array_column($trace, 'change'),
        );
        self::assertSame([2, 5, 5, 3], array_column($trace, 'steps'));
        self::assertSame(['depth' => 2, 'name' => 'send event', 'destination' => 'order.placed', 'kind' => 'Server', 'status' => 'Ok'], $trace[1]['step']);
        self::assertSame(['depth' => 3, 'name' => 'route audit', 'destination' => 'audit.target', 'kind' => 'Producer', 'status' => 'Ok'], $trace[2]['step']);
        self::assertSame(['depth' => 4, 'name' => 'process class@anonymous::audit', 'kind' => 'Server', 'status' => 'Ok'], $trace[3]['step']);
    }

    public function test_a_short_trace_keeps_every_unchanged_step_as_context(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAudit());

        self::assertSame(
            ['unchanged', 'unchanged', 'unchanged', 'added', 'added', 'unchanged', 'unchanged', 'unchanged', 'unchanged'],
            array_column($this->comparison()['groups'][0]['trace'], 'change'),
        );
    }

    public function test_scenarios_whose_change_is_identical_are_grouped(): void
    {
        foreach (['First ordering', 'Second ordering'] as $scenario) {
            $this->record($scenario, 'before', $this->ordering());
            $this->record($scenario, 'after', $this->orderingWithAudit());
        }
        $this->record('Third ordering', 'before', $this->ordering());
        $this->record('Third ordering', 'after', $this->orderingWithManySubscribers());

        self::assertSame([['First ordering', 'Second ordering'], ['Third ordering']], array_column($this->comparison()['groups'], 'scenarios'));
    }

    public function test_a_scenario_stored_on_one_side_only_is_reported_as_added_or_removed_without_its_whole_wiring(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $this->record('Auditing an order', 'after', $this->orderingWithAudit());
        $this->record('Retired ordering', 'before', $this->ordering());

        $groups = $this->comparison()['groups'];

        self::assertSame([['Auditing an order'], ['Retired ordering']], array_column($groups, 'scenarios'));
        self::assertSame(['added', 'removed'], array_column($groups, 'state'));
        self::assertSame([[], []], array_column($groups, 'wiringParts'));
        self::assertSame('added', $groups[0]['trace'][0]['change']);
        self::assertSame('removed', $groups[1]['trace'][0]['change']);
    }

    public function test_comparing_with_a_location_that_does_not_exist_is_refused_with_the_command_that_writes_it(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());

        $this->expectExceptionMessage("Flow snapshots were expected in {$this->location}/after, but that directory does not exist. Pass the directory the snapshots were written to, or write them first with `php bin/build-flow.php snapshot --location={$this->location}/after`.");

        $this->comparison();
    }

    public function test_comparing_a_file_that_is_not_a_snapshot_is_refused_naming_the_file(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        file_put_contents($this->location . '/after/placing-an-order.json', '{"something": "else"}');

        $this->expectExceptionMessage("Flow snapshot file {$this->location}/after/placing-an-order.json does not hold `scenario`, `wiring`, `build`, `trace` and `execution`, so it was not written by this version of the build flow tooling. Restore it from version control, or regenerate every snapshot with `php bin/build-flow.php snapshot`.");

        $this->comparison();
    }

    public function test_comparing_a_snapshot_that_lost_the_wiring_it_shares_is_refused_naming_the_missing_file(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $stored = json_decode((string) file_get_contents($this->location . '/after/placing-an-order.json'), true, flags: JSON_THROW_ON_ERROR);
        $stored['wiring'] += ['withoutNodes' => [], 'withoutEdges' => []];
        file_put_contents($this->location . '/after/placing-an-order.json', json_encode($stored, JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage("Flow snapshot file {$this->location}/after/placing-an-order.json stores only what its scenario adds to what every application gets, but {$this->location}/after/shared.json is missing, so its whole wiring and build cannot be read. Restore that file from version control, or regenerate every snapshot with `php bin/build-flow.php snapshot`.");

        $this->comparison();
    }

    private function ordering(): object
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

    private function orderingWithAudit(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[EventHandler('order.placed', endpointId: 'audit')]
            public function audit(): void
            {
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }
        };
    }

    private function orderingWithAnInterceptorAroundEveryBus(): object
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

            #[Before(pointcut: CommandBus::class . '||' . QueryBus::class . '||' . EventBus::class . '||' . MessagingTestSupport::class)]
            public function stamp(): void
            {
            }
        };
    }

    private function orderingWithAuditAndStatus(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[EventHandler('order.placed', endpointId: 'audit')]
            public function audit(): void
            {
            }

            #[QueryHandler('order.status', endpointId: 'orderStatus')]
            public function status(): string
            {
                return 'placed';
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }
        };
    }

    private function orderingWithManySubscribers(): object
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

            #[EventHandler('order.paid', endpointId: 'whenPaid')]
            public function whenPaid(): void
            {
            }

            #[EventHandler('order.packed', endpointId: 'whenPacked')]
            public function whenPacked(): void
            {
            }

            #[EventHandler('order.shipped', endpointId: 'whenShipped')]
            public function whenShipped(): void
            {
            }

            #[EventHandler('order.delivered', endpointId: 'whenDelivered')]
            public function whenDelivered(): void
            {
            }

            #[EventHandler('order.returned', endpointId: 'whenReturned')]
            public function whenReturned(): void
            {
            }

            #[EventHandler('order.refunded', endpointId: 'whenRefunded')]
            public function whenRefunded(): void
            {
            }

            #[EventHandler('order.cancelled', endpointId: 'whenCancelled')]
            public function whenCancelled(): void
            {
            }
        };
    }

    private function record(string $scenario, string $side, object $ordering, int $times = 1): void
    {
        $ecotone = $this->application([$ordering::class], [$ordering]);
        for ($sent = 0; $sent < $times; $sent++) {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        }

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => $scenario, 'location' => $this->location . '/' . $side]);
    }

    /**
     * @return array<string, mixed>
     */
    private function comparison(): array
    {
        $ecotone = $this->application([], []);

        $ecotone->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after', 'presentation' => 'json']);

        return json_decode($ecotone->getInMemoryConsoleWriter()->getWrittenContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     */
    private function application(array $classes, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [...$classes, BuildFlowConsoleCommands::class],
            [...$services, new BuildFlowConsoleCommands(), InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withExecutionRecording()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
