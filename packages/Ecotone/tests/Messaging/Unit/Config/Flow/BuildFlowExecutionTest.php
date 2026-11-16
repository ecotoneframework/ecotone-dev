<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Interceptor\MethodInvocation;
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
        $firstChange = array_key_first(array_filter($changes, fn (string $change): bool => in_array($change, ['added', 'removed'], true)));
        self::assertSame('unchanged', $changes[$firstChange - 1]);
        self::assertContains('handler class@anonymous::audit returns nothing', array_map(trim(...), array_column(array_filter($execution[0]['steps'], fn (array $entry): bool => $entry['change'] === 'added'), 'step')));
    }

    public function test_a_process_that_runs_only_after_the_change_is_shown_whole(): void
    {
        $this->record('Placing an order', 'before', $this->orderingWithAudit(), executionRecording: true, consumed: false);
        $this->record('Placing an order', 'after', $this->orderingWithAudit(), executionRecording: true);

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(['changed', 'added'], array_column($execution, 'change'));
        self::assertSame(
            [
                ['change' => 'removed', 'step' => 'release collected message to notifications, held by MessageCollectorChannelInterceptor on its first send'],
                ['change' => 'added', 'step' => 'release collected message to notifications, held by MessageCollectorChannelInterceptor on its first send; taken from the channel later by a separate consumer run, process `receive notifications, handled by class@anonymous::whenPlaced`'],
            ],
            array_values(array_map(fn (array $entry): array => ['change' => $entry['change'], 'step' => trim($entry['step'])], array_filter($execution[0]['steps'], fn (array $entry): bool => in_array($entry['change'], ['added', 'removed'], true)))),
        );
        self::assertSame(
            str_replace('by process 1', 'by process `send command order.place, handled by class@anonymous::place`', $this->storedSnapshot('after', 'placing-an-order')['execution'][1]['steps']),
            array_column($execution[1]['steps'], 'step'),
        );
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
        self::assertMatchesRegularExpression('/```diff\n(  .*\n|  … \d+ unchanged steps\n|\+ .*\n|- .*\n)+```/', $presented);
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

    public function test_steps_that_only_run_deeper_under_a_new_step_are_reported_as_moved_under_it_with_their_count(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->orderingWrappedInAround(), executionRecording: true);

        $presented = $this->presented('A');

        self::assertMatchesRegularExpression('/\n\+ +around class@anonymous::wrap\n {4,}… around MessageHandlerLogger::aroundCommandHandler \(\d+ steps\): unchanged, now run under the around class@anonymous::wrap step\n/', $presented);
        self::assertDoesNotMatchRegularExpression('/\n- /', $this->whatRan($presented));
    }

    public function test_steps_that_moved_and_changed_inside_show_the_move_once_and_only_their_changed_steps(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->orderingWithAuditWrappedInAround(), executionRecording: true);

        $presented = $this->presented('A');

        self::assertMatchesRegularExpression('/\n {4,}… around MessageHandlerLogger::aroundCommandHandler \(\d+ steps\): moved, now run under the around class@anonymous::wrap step, and inside it:\n/', $presented);
        self::assertSame(1, substr_count($presented, 'MessageHandlerLogger::aroundCommandHandler'));
        self::assertMatchesRegularExpression('/\n\+ +handler class@anonymous::audit returns nothing\n/', $presented);
        preg_match_all('/^- +(.+)$/m', $this->whatRan($presented), $removed);
        self::assertSame(['route by name order.placed to whenOrderWasPlaced.target'], $removed[1]);
    }

    public function test_steps_that_lost_the_step_they_ran_under_are_reported_as_moved_out_of_it(): void
    {
        $this->record('Placing an order', 'before', $this->orderingWrappedInAround(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->ordering(), executionRecording: true);

        $presented = $this->presented('A');

        self::assertMatchesRegularExpression('/\n- +around class@anonymous::wrap\n {4,}… around MessageHandlerLogger::aroundCommandHandler \(\d+ steps\): unchanged, no longer run under the around class@anonymous::wrap step\n/', $presented);
        self::assertDoesNotMatchRegularExpression('/\n\+ /', $this->whatRan($presented));
    }

    public function test_steps_that_changed_their_order_of_execution_stay_removed_and_added(): void
    {
        $this->record('Placing an order', 'before', $this->orderingWithTwoArounds(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->orderingWithTwoAroundsSwapped(), executionRecording: true);

        $presented = $this->presented('A');

        self::assertMatchesRegularExpression('/\n- +around class@anonymous::first\n- +around class@anonymous::second\n\+ +around class@anonymous::second\n\+ +around class@anonymous::first\n/', $presented);
        self::assertStringNotContainsString('run under', $this->whatRan($presented));
    }

    public function test_a_process_whose_steps_only_moved_says_they_moved_under_another_step(): void
    {
        $this->record('Placing an order', 'before', $this->orderingPublishingBothEvents(), executionRecording: true, consumed: false);
        $this->record('Placing an order', 'after', $this->orderingPublishingTheSecondEventFromTheFirstHandler(), executionRecording: true, consumed: false);

        $presented = $this->presented('A');

        self::assertMatchesRegularExpression('/\nProcess `send command order\.place, handled by class@anonymous::place`: runs the same steps, \d+ of them now under another step\.\n/', $presented);
        self::assertMatchesRegularExpression('/\n {4,}… gateway EventBus::publishWithRouting returns nothing \(\d+ steps\): unchanged, now run under the handler class@anonymous::whenPlaced step\n/', $presented);
        self::assertDoesNotMatchRegularExpression('/\n[-+] /', $this->whatRan($presented));
    }

    public function test_the_traced_steps_marked_new_are_the_same_when_what_ran_shows_steps_moved_under_a_new_step(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->orderingForwardedThroughACommand(), executionRecording: true);

        $presented = $this->presented('A');

        preg_match_all('/^ *(.+?)   ← new$/m', $presented, $new);
        self::assertSame(['send command order.forward', 'process command handler forward', 'send event order.placed', 'route whenOrderWasPlaced → whenOrderWasPlaced.target'], $new[1]);
        self::assertMatchesRegularExpression('/\n {4,}… gateway EventBus::publishWithRouting returns nothing \(\d+ steps\): (unchanged|moved), now run under the handler class@anonymous::forward step/', $presented);
    }

    public function test_two_alike_steps_that_moved_are_each_paired_with_their_own_counterpart(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->ordering(), executionRecording: true);
        $sends = fn (string $indent): array => [
            $indent . 'entrypoint sends to notifications',
            $indent . '  send notifications; taken from the channel later by a separate consumer run, process 2',
            $indent . '    put on channel notifications',
            $indent . 'entrypoint sends to notifications',
            $indent . '  send notifications; taken from the channel later by a separate consumer run, process 3',
            $indent . '    put on channel notifications',
        ];
        $consumers = [
            ['process' => 'receive notifications (1 of 2)', 'steps' => ['  receive notifications: the message, put on the channel by process 1']],
            ['process' => 'receive notifications (2 of 2)', 'steps' => ['  receive notifications: the message, put on the channel by process 1']],
        ];
        $this->replaceExecution('before', [['process' => 'dispatch', 'steps' => ['  handler class@anonymous::dispatch returns nothing', ...$sends('    ')]], ...$consumers]);
        $this->replaceExecution('after', [['process' => 'dispatch', 'steps' => ['  handler class@anonymous::dispatch returns nothing', '    dispatch 2 batches', ...$sends('      ')]], ...$consumers]);

        $presented = $this->whatRan($this->presented('A'));

        self::assertSame(2, substr_count($presented, '… entrypoint sends to notifications (3 steps): unchanged, now run under the dispatch step'));
        self::assertDoesNotMatchRegularExpression('/\n- /', $presented);
    }

    public function test_a_process_referred_to_by_number_is_compared_by_what_it_is_so_a_renumbered_process_stays_unchanged(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), executionRecording: true);
        $this->record('Placing an order', 'after', $this->ordering(), executionRecording: true);
        $sender = ['process' => 'send command order.place', 'steps' => ['  send q; taken from the channel later by a separate consumer run, process 2']];
        $gatherer = fn (int $sender): array => ['process' => 'receive q, handled by the gatherer only, recording part 1 of 1 reported by process ' . $sender . ', whose work ran there', 'steps' => ['  receive q: the part report for part 1 of 1, put on the channel by process ' . $sender]];
        $this->replaceExecution('before', [$sender, $gatherer(1)]);
        $this->replaceExecution('after', [['process' => 'initialize', 'steps' => ['  handler class@anonymous::init returns nothing']], ['process' => 'send command order.place', 'steps' => ['  send q; taken from the channel later by a separate consumer run, process 3']], $gatherer(2)]);

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(
            [
                ['process' => 'initialize', 'change' => 'added'],
                ['process' => 'send command order.place', 'change' => 'unchanged'],
                ['process' => 'receive q, handled by the gatherer only, recording part 1 of 1 reported by process `send command order.place`, whose work ran there', 'change' => 'unchanged'],
            ],
            array_map(fn (array $process): array => ['process' => $process['process'], 'change' => $process['change']], $execution),
        );
    }

    /**
     * @param list<array{process: string, steps: list<string>}> $execution
     */
    private function replaceExecution(string $side, array $execution): void
    {
        $file = $this->location . '/' . $side . '/placing-an-order.json';
        $snapshot = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $snapshot['execution'] = $execution;
        file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
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

    private function orderingWrappedInAround(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[Around(pointcut: CommandHandler::class)]
            public function wrap(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }
        };
    }

    private function orderingWithAuditWrappedInAround(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[Around(pointcut: CommandHandler::class)]
            public function wrap(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
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

    private function orderingWithTwoArounds(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order): void
            {
            }

            #[Around(precedence: 1, pointcut: CommandHandler::class)]
            public function first(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
            }

            #[Around(precedence: 2, pointcut: CommandHandler::class)]
            public function second(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
            }
        };
    }

    private function orderingWithTwoAroundsSwapped(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order): void
            {
            }

            #[Around(precedence: 2, pointcut: CommandHandler::class)]
            public function first(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
            }

            #[Around(precedence: 1, pointcut: CommandHandler::class)]
            public function second(MethodInvocation $invocation): mixed
            {
                return $invocation->proceed();
            }
        };
    }

    private function orderingPublishingBothEvents(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
                $eventBus->publishWithRouting('order.paid', []);
            }

            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(array $event, EventBus $eventBus): void
            {
            }

            #[EventHandler('order.paid', endpointId: 'whenOrderWasPaid')]
            public function whenPaid(): void
            {
            }
        };
    }

    private function orderingPublishingTheSecondEventFromTheFirstHandler(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(array $event, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.paid', []);
            }

            #[EventHandler('order.paid', endpointId: 'whenOrderWasPaid')]
            public function whenPaid(): void
            {
            }
        };
    }

    private function orderingForwardedThroughACommand(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('order.forward', []);
            }

            #[CommandHandler('order.forward', endpointId: 'forwardOrder')]
            public function forward(array $order, EventBus $eventBus): void
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

    private function whatRan(string $presented): string
    {
        return substr($presented, (int) strpos($presented, '#### What ran, process by process'));
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
