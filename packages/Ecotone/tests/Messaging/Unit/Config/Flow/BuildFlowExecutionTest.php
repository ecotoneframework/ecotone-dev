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
final class BuildFlowExecutionTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-build-flow-execution-' . bin2hex(random_bytes(6));
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

    public function test_recorded_snapshot_holds_what_ran_in_each_process(): void
    {
        $this->record('Placing an order', 'after', $this->ordering(), executionRecording: true);

        $execution = $this->storedSnapshot('after', 'placing-an-order')['execution'];

        self::assertSame(['send command order.place, handled by class@anonymous::place', 'receive notifications, handled by class@anonymous::whenPlaced'], array_column($execution, 'process'));
        self::assertSame('  gateway CommandBus::sendWithRouting returns nothing', $execution[0]['steps'][0]);
        self::assertContains('handler class@anonymous::whenPlaced returns nothing', array_map(trim(...), $execution[1]['steps']));
    }

    public function test_recording_the_execution_leaves_the_stored_trace_as_it_is_without_it(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: false);
        $this->record('Placing an order', 'after', $this->ordering(), executionRecording: true);

        self::assertSame($this->storedSnapshot('before', 'placing-an-order')['trace'], $this->storedSnapshot('after', 'placing-an-order')['trace']);
        self::assertSame([], $this->storedSnapshot('before', 'placing-an-order')['execution']);
    }

    public function test_a_changed_process_shows_its_differing_steps_among_unchanged_neighbours_and_an_unchanged_process_is_one_line(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->orderingWithAudit(), executionRecording: true);

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(
            [['process' => 'send command order.place, handled by class@anonymous::place', 'change' => 'changed'], ['process' => 'receive notifications, handled by class@anonymous::whenPlaced', 'change' => 'unchanged']],
            array_map(fn (array $process): array => ['process' => $process['process'], 'change' => $process['change']], $execution),
        );
        self::assertArrayNotHasKey('steps', $execution[1]);
        $changes = array_column($execution[0]['steps'], 'change');
        self::assertContains('left out', $changes);
        self::assertSame('unchanged', $changes[array_search('added', $changes, true) - 1]);
        self::assertContains('handler class@anonymous::audit returns nothing', array_map(trim(...), array_column(array_filter($execution[0]['steps'], fn (array $entry): bool => $entry['change'] === 'added'), 'step')));
    }

    public function test_a_process_that_runs_only_after_the_change_is_shown_whole(): void
    {
        $this->record('Placing an order', 'before', $this->orderingWithAudit(), executionRecording: true, consumed: false);
        $this->record('Placing an order', 'after', $this->orderingWithAudit(), executionRecording: true);

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(['unchanged', 'added'], array_column($execution, 'change'));
        self::assertSame($this->storedSnapshot('after', 'placing-an-order')['execution'][1]['steps'], array_column($execution[1]['steps'], 'step'));
        self::assertSame(['added'], array_values(array_unique(array_column($execution[1]['steps'], 'change'))));
    }

    public function test_the_presentation_shows_what_ran_per_process_as_a_diff(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->orderingWithAudit(), executionRecording: true);

        $presented = $this->presented('A');

        self::assertStringContainsString("#### What ran, process by process\n", $presented);
        self::assertStringContainsString("Process `receive notifications, handled by class@anonymous::whenPlaced`: unchanged.\n", $presented);
        self::assertMatchesRegularExpression('/\n\+ +handler class@anonymous::audit returns nothing\n/', $presented);
        self::assertMatchesRegularExpression('/```diff\n(  .*\n|  … \d+ unchanged steps\n|\+ .*\n)+```/', $presented);
    }

    public function test_processes_of_the_same_kind_are_titled_by_what_they_handled_and_paired_on_it(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->orderingWithAsynchronousAudit(), executionRecording: true);

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(
            [
                ['process' => 'send command order.place, handled by class@anonymous::place', 'change' => 'changed'],
                ['process' => 'receive notifications, handled by class@anonymous::audit', 'change' => 'added'],
                ['process' => 'receive notifications, handled by class@anonymous::whenPlaced', 'change' => 'unchanged'],
            ],
            array_map(fn (array $process): array => ['process' => $process['process'], 'change' => $process['change']], $execution),
        );
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

    private function orderingWithAsynchronousAudit(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[Asynchronous('notifications')]
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

    private function record(string $scenario, string $side, object $ordering, bool $executionRecording, bool $consumed = true): void
    {
        $ecotone = $this->application([$ordering::class], [$ordering], $executionRecording);
        $ecotone->sendCommandWithRouting('order.place', []);
        if ($consumed) {
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());
        }

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => $scenario, 'location' => $this->location . '/' . $side]);
    }

    /**
     * @return array<string, mixed>
     */
    private function comparison(): array
    {
        return json_decode($this->presented('json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function presented(string $presentation): string
    {
        $ecotone = $this->application([], [], false);

        $ecotone->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after', 'presentation' => $presentation]);

        return $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function storedSnapshot(string $side, string $fileName): array
    {
        return json_decode((string) file_get_contents($this->location . '/' . $side . '/' . $fileName . '.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     */
    private function application(array $classes, array $services, bool $executionRecording): FlowTestSupport
    {
        $tracing = TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class);

        return EcotoneLite::bootstrapFlowTesting(
            [...$classes, BuildFlowConsoleCommands::class],
            [...$services, new BuildFlowConsoleCommands(), InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), $executionRecording ? $tracing->withExecutionRecording() : $tracing],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
