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
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
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
        $this->record('Placing an order', 'after', $this->ordering());

        $execution = $this->storedSnapshot('after', 'placing-an-order')['execution'];

        self::assertSame(['send command order.place, class@anonymous::place', 'receive notifications, class@anonymous::whenPlaced'], array_column($execution, 'process'));
        self::assertSame(['depth' => 1, 'label' => 'gateway CommandBus::sendWithRouting', 'hints' => [['key' => 'returns', 'value' => 'nothing', 'shownInLines' => false]]], $execution[0]['steps'][0]);
        self::assertContains('handler class@anonymous::whenPlaced', array_column($execution[1]['steps'], 'label'));
    }

    public function test_the_stored_snapshot_holds_the_process_and_step_that_sent_the_message_of_a_consumer_run(): void
    {
        $this->record('Placing an order', 'after', $this->ordering());

        $execution = $this->storedSnapshot('after', 'placing-an-order')['execution'];

        self::assertArrayNotHasKey('sentBy', $execution[0]);
        self::assertSame(1, $execution[1]['sentBy']['processNumber']);
        self::assertSame('release collected message to notifications', $execution[0]['steps'][$execution[1]['sentBy']['stepNumber'] - 1]['label']);
    }

    public function test_a_changed_process_shows_its_differing_steps_among_unchanged_neighbours_and_an_unchanged_process_is_one_line(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAudit());

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(
            [['process' => 'send command order.place, class@anonymous::place', 'change' => 'changed'], ['process' => 'receive notifications, class@anonymous::whenPlaced', 'change' => 'unchanged']],
            array_map(fn (array $process): array => ['process' => $process['process'], 'change' => $process['change']], $execution),
        );
        self::assertArrayNotHasKey('steps', $execution[1]);
        $changes = array_column($execution[0]['steps'], 'change');
        self::assertContains('left out', $changes);
        $firstChange = array_key_first(array_filter($changes, fn (string $change): bool => in_array($change, ['added', 'removed'], true)));
        self::assertSame('unchanged', $changes[$firstChange - 1]);
        self::assertContains('handler class@anonymous::audit', array_map(trim(...), array_column(array_filter($execution[0]['steps'], fn (array $entry): bool => $entry['change'] === 'added'), 'step')));
    }

    public function test_a_process_that_runs_only_after_the_change_is_shown_whole(): void
    {
        $this->record('Placing an order', 'before', $this->orderingWithAudit(), consumed: false);
        $this->record('Placing an order', 'after', $this->orderingWithAudit());

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(['changed', 'added'], array_column($execution, 'change'));
        self::assertSame(
            [['change' => 'changed', 'step' => 'release collected message to notifications', 'hintChanges' => [['key' => 'taken by', 'before' => '', 'now' => 'process `receive notifications, class@anonymous::whenPlaced`, a separate consumer run', 'shownInLines' => true]]]],
            array_values(array_map(fn (array $entry): array => ['change' => $entry['change'], 'step' => trim($entry['step']), 'hintChanges' => $entry['hintChanges']], array_filter($execution[0]['steps'], fn (array $entry): bool => $entry['change'] !== 'unchanged' && $entry['change'] !== 'left out'))),
        );
        self::assertSame(
            array_map(fn (array $step): string => str_repeat('  ', $step['depth']) . $step['label'], $this->storedSnapshot('after', 'placing-an-order')['execution'][1]['steps']),
            array_column($execution[1]['steps'], 'step'),
        );
        self::assertSame(['added'], array_values(array_unique(array_column($execution[1]['steps'], 'change'))));
    }

    public function test_a_consumer_run_that_runs_only_after_the_change_is_stated_as_a_new_sub_flow_and_shown_as_one(): void
    {
        $this->record('Placing an order', 'before', $this->ordering(), consumed: false);
        $this->record('Placing an order', 'after', $this->ordering());

        $whatRan = $this->whatRan($this->presented('before-after'));

        self::assertStringStartsWith("#### What ran, process by process\n\n1 new sub-flow of `send command order.place, class@anonymous::place`.\n\n", $whatRan);
        self::assertStringContainsString('Process `receive notifications, class@anonymous::whenPlaced`, a sub-flow of `send command order.place, class@anonymous::place`: runs only now.', $whatRan);
    }

    public function test_a_consumer_run_that_ran_only_before_the_change_is_stated_as_a_gone_sub_flow(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering(), consumed: false);

        self::assertStringStartsWith("#### What ran, process by process\n\n1 sub-flow of `send command order.place, class@anonymous::place` gone.\n\n", $this->whatRan($this->presented('before-after')));
    }

    public function test_a_sub_flow_attached_to_a_different_sender_is_a_change_and_each_sub_flow_is_shown_under_its_sender(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $step = ['depth' => 1, 'label' => 'handler Orders::handle', 'hints' => []];
        $this->replaceExecution('before', [
            ['process' => 'send command order.place', 'steps' => [$step]],
            ['process' => 'send command order.cancel', 'steps' => [$step]],
            ['process' => 'receive notifications', 'sentBy' => ['processNumber' => 1, 'stepNumber' => 1], 'steps' => [$step]],
        ]);
        $this->replaceExecution('after', [
            ['process' => 'send command order.place', 'steps' => [$step]],
            ['process' => 'send command order.cancel', 'steps' => [$step]],
            ['process' => 'receive notifications', 'sentBy' => ['processNumber' => 2, 'stepNumber' => 1], 'steps' => [$step]],
        ]);

        $whatRan = $this->whatRan($this->presented('before-after'));

        self::assertSame(['unchanged', 'unchanged', 'changed'], array_column($this->comparison()['groups'][0]['execution'], 'change'));
        self::assertSame('Now: `receive notifications` is now a sub-flow of `send command order.cancel`, before of `send command order.place`.', $this->comparison()['groups'][0]['summary']['now']);
        self::assertStringStartsWith("#### What ran, process by process\n\n`receive notifications` is now a sub-flow of `send command order.cancel`, before of `send command order.place`.\n\n", $whatRan);
        self::assertSame(
            ['Process `send command order.place`', 'Process `send command order.cancel`', 'Process `receive notifications`, a sub-flow of `send command order.cancel`'],
            array_map(fn (string $section): string => explode(':', $section, 2)[0], array_values(preg_grep('/^Process `/', explode("\n", $whatRan)))),
        );
    }

    public function test_a_sub_flow_whose_sender_was_removed_and_added_again_under_the_same_title_is_shown_under_the_sender_that_runs_now(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $step = ['depth' => 1, 'label' => 'handler Orders::handle', 'hints' => []];
        $this->replaceExecution('before', [
            ['process' => 'send command order.place', 'steps' => [$step]],
            ['process' => 'send command order.cancel', 'steps' => [$step]],
            ['process' => 'receive notifications', 'sentBy' => ['processNumber' => 1, 'stepNumber' => 1], 'steps' => [$step]],
        ]);
        $this->replaceExecution('after', [
            ['process' => 'send command order.cancel', 'steps' => [$step]],
            ['process' => 'send command order.place', 'steps' => [$step]],
            ['process' => 'receive notifications', 'sentBy' => ['processNumber' => 2, 'stepNumber' => 1], 'steps' => [$step]],
        ]);

        $sections = array_values(preg_grep('/^Process `/', explode("\n", $this->whatRan($this->presented('before-after')))));

        self::assertSame('Process `receive notifications`, a sub-flow of `send command order.place`: unchanged.', $sections[(int) array_search('Process `send command order.place`: runs only now.', $sections, true) + 1]);
    }

    public function test_sub_flows_are_shown_right_after_the_flow_that_sent_their_message_in_the_order_it_sent_them(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $step = ['depth' => 1, 'label' => 'handler Orders::handle', 'hints' => []];
        $this->replaceExecution('before', []);
        $this->replaceExecution('after', [
            ['process' => 'send command order.place', 'steps' => [$step, $step]],
            ['process' => 'send command order.cancel', 'steps' => [$step]],
            ['process' => 'receive audit', 'sentBy' => ['processNumber' => 1, 'stepNumber' => 2], 'steps' => [$step]],
            ['process' => 'receive notifications', 'sentBy' => ['processNumber' => 1, 'stepNumber' => 1], 'steps' => [$step]],
        ]);

        $whatRan = $this->whatRan($this->presented('before-after'));

        self::assertSame(
            ['Process `send command order.place`', 'Process `receive notifications`, a sub-flow of `send command order.place`', 'Process `receive audit`, a sub-flow of `send command order.place`', 'Process `send command order.cancel`'],
            array_map(fn (string $section): string => explode(':', $section, 2)[0], array_values(preg_grep('/^Process `/', explode("\n", $whatRan)))),
        );
        self::assertStringStartsWith("#### What ran, process by process\n\n2 new sub-flows of `send command order.place`.\n\n", $whatRan);
    }

    public function test_the_presentation_shows_what_ran_per_process_as_a_diff(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAudit());

        $presented = $this->presented('before-after');

        self::assertStringContainsString("#### What ran, process by process\n", $presented);
        self::assertStringContainsString("Process `receive notifications, class@anonymous::whenPlaced`, a sub-flow of `send command order.place, class@anonymous::place`: unchanged.\n", $presented);
        self::assertMatchesRegularExpression('/\n\+ +handler class@anonymous::audit\n/', $presented);
        self::assertMatchesRegularExpression('/```diff\n(  .*\n|  … \d+ unchanged steps\n|\+ .*\n|- .*\n)+```/', $presented);
    }

    public function test_processes_of_the_same_kind_are_titled_by_what_they_handled_and_paired_on_it(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAsynchronousAudit());

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(
            [
                ['process' => 'send command order.place, class@anonymous::place', 'change' => 'changed'],
                ['process' => 'receive notifications, class@anonymous::audit', 'change' => 'added'],
                ['process' => 'receive notifications, class@anonymous::whenPlaced', 'change' => 'unchanged'],
            ],
            array_map(fn (array $process): array => ['process' => $process['process'], 'change' => $process['change']], $execution),
        );
    }

    public function test_steps_that_only_run_deeper_under_a_new_step_are_reported_as_moved_under_it_with_their_count(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWrappedInAround());

        $presented = $this->presented('before-after');

        self::assertMatchesRegularExpression('/\n\+ +around class@anonymous::wrap\n {4,}… around MessageHandlerLogger::aroundCommandHandler \(\d+ steps\): unchanged, now run under the around class@anonymous::wrap step\n/', $presented);
        self::assertDoesNotMatchRegularExpression('/\n- /', $this->whatRan($presented));
    }

    public function test_steps_that_moved_and_changed_inside_show_the_move_once_and_only_their_changed_steps(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithAuditWrappedInAround());

        $presented = $this->presented('before-after');

        self::assertMatchesRegularExpression('/\n {4,}… around MessageHandlerLogger::aroundCommandHandler \(\d+ steps\): moved, now run under the around class@anonymous::wrap step, and inside it:\n/', $presented);
        self::assertSame(1, substr_count($presented, 'MessageHandlerLogger::aroundCommandHandler'));
        self::assertMatchesRegularExpression('/\n\+ +handler class@anonymous::audit\n/', $presented);
        preg_match_all('/^- +(.+)$/m', $this->whatRan($presented), $removed);
        self::assertSame(['route to whenOrderWasPlaced.target', '↳ chosen by: name order.placed'], $removed[1]);
    }

    public function test_steps_that_lost_the_step_they_ran_under_are_reported_as_moved_out_of_it(): void
    {
        $this->record('Placing an order', 'before', $this->orderingWrappedInAround());
        $this->record('Placing an order', 'after', $this->ordering());

        $presented = $this->presented('before-after');

        self::assertMatchesRegularExpression('/\n- +around class@anonymous::wrap\n {4,}… around MessageHandlerLogger::aroundCommandHandler \(\d+ steps\): unchanged, no longer run under the around class@anonymous::wrap step\n/', $presented);
        self::assertDoesNotMatchRegularExpression('/\n\+ /', $this->whatRan($presented));
    }

    public function test_steps_that_changed_their_order_of_execution_stay_removed_and_added(): void
    {
        $this->record('Placing an order', 'before', $this->orderingWithTwoArounds());
        $this->record('Placing an order', 'after', $this->orderingWithTwoAroundsSwapped());

        $presented = $this->presented('before-after');

        self::assertMatchesRegularExpression('/\n- +around class@anonymous::first\n- +around class@anonymous::second\n\+ +around class@anonymous::second\n\+ +around class@anonymous::first\n/', $presented);
        self::assertStringNotContainsString('run under', $this->whatRan($presented));
    }

    public function test_a_process_whose_steps_only_moved_says_they_moved_under_another_step(): void
    {
        $this->record('Placing an order', 'before', $this->orderingPublishingBothEvents(), consumed: false);
        $this->record('Placing an order', 'after', $this->orderingPublishingTheSecondEventFromTheFirstHandler(), consumed: false);

        $presented = $this->presented('before-after');

        self::assertMatchesRegularExpression('/\nProcess `send command order\.place, class@anonymous::place`: runs the same steps, \d+ of them now under another step\.\n/', $presented);
        self::assertMatchesRegularExpression('/\n {4,}… gateway EventBus::publishWithRouting \(\d+ steps\): unchanged, now run under the handler class@anonymous::whenPlaced step\n/', $presented);
        self::assertDoesNotMatchRegularExpression('/\n[-+] /', $this->whatRan($presented));
    }

    public function test_the_traced_steps_marked_new_are_the_same_when_what_ran_shows_steps_moved_under_a_new_step(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingForwardedThroughACommand());

        $presented = $this->presented('before-after');

        preg_match_all('/^ *(.+?)   ← new$/m', $presented, $new);
        self::assertSame(['send command order.forward', 'process command handler forward', 'send event order.placed', 'route whenOrderWasPlaced → whenOrderWasPlaced.target'], $new[1]);
        self::assertMatchesRegularExpression('/\n {4,}… gateway EventBus::publishWithRouting \(\d+ steps\): (unchanged|moved), now run under the handler class@anonymous::forward step/', $presented);
    }

    public function test_two_alike_steps_that_moved_are_each_paired_with_their_own_counterpart(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $takenBy = fn (int $process): array => [['key' => 'taken by', 'value' => 'process ' . $process . ', a separate consumer run', 'shownInLines' => true]];
        $sends = fn (int $depth): array => [
            ['depth' => $depth, 'label' => 'entrypoint sends to notifications', 'hints' => []],
            ['depth' => $depth + 1, 'label' => 'send notifications', 'hints' => $takenBy(2)],
            ['depth' => $depth + 2, 'label' => 'put on channel notifications', 'hints' => []],
            ['depth' => $depth, 'label' => 'entrypoint sends to notifications', 'hints' => []],
            ['depth' => $depth + 1, 'label' => 'send notifications', 'hints' => $takenBy(3)],
            ['depth' => $depth + 2, 'label' => 'put on channel notifications', 'hints' => []],
        ];
        $consumer = fn (string $ordinal): array => ['process' => 'receive notifications (' . $ordinal . ')', 'steps' => [['depth' => 1, 'label' => 'receive notifications', 'hints' => [['key' => 'sent by', 'value' => 'process 1, the message', 'shownInLines' => true]]]]];
        $handler = ['depth' => 1, 'label' => 'handler class@anonymous::dispatch', 'hints' => []];
        $this->replaceExecution('before', [['process' => 'dispatch', 'steps' => [$handler, ...$sends(2)]], $consumer('1 of 2'), $consumer('2 of 2')]);
        $this->replaceExecution('after', [['process' => 'dispatch', 'steps' => [$handler, ['depth' => 2, 'label' => 'dispatch 2 batches', 'hints' => []], ...$sends(3)]], $consumer('1 of 2'), $consumer('2 of 2')]);

        $presented = $this->whatRan($this->presented('before-after'));

        self::assertSame(2, substr_count($presented, '… entrypoint sends to notifications (3 steps): unchanged, now run under the dispatch step'));
        self::assertDoesNotMatchRegularExpression('/\n- /', $presented);
    }

    public function test_the_summary_states_a_repeated_change_once_without_explaining_the_tool_and_names_the_group_and_the_gatherer_runs(): void
    {
        $baseline = __DIR__ . '/../../../../BuildFlow/baseline/';
        $file = 'tag-partitioned-projection-backfilled-asynchronously-in-two-batches.json';
        $after = json_decode((string) file_get_contents($baseline . $file), true, flags: JSON_THROW_ON_ERROR);
        $before = $after;
        $before['trace'] = array_values(array_filter($after['trace'], fn (array $step, int $position): bool => ! in_array($position, [5, 6, 11, 12], true), ARRAY_FILTER_USE_BOTH));
        $before['execution'] = array_values(array_filter($after['execution'], fn (array $process): bool => ! str_contains($process['process'], 'GatheringMessageHandler::handle')));
        $before['execution'][0]['steps'] = array_values(array_filter($before['execution'][0]['steps'], fn (array $step): bool => $step['label'] !== 'open gathering group of 2 parts'));
        foreach (['before' => $before, 'after' => $after] as $side => $snapshot) {
            mkdir($this->location . '/' . $side, recursive: true);
            copy($baseline . 'shared.json', $this->location . '/' . $side . '/shared.json');
            file_put_contents($this->location . '/' . $side . '/' . $file, json_encode($snapshot, JSON_THROW_ON_ERROR));
        }

        $now = $this->comparison()['groups'][0]['summary']['now'];

        self::assertStringNotContainsString('the span shows only', $now);
        self::assertStringNotContainsString('from the wiring', $now);
        self::assertSame(1, substr_count($now, 'sends a part report'));
        self::assertStringContainsString('opens a gathering group of 2 parts', $now);
        self::assertStringContainsString('2 gatherer runs take the part reports in', $now);
    }

    public function test_a_flow_with_a_routing_key_that_is_not_valid_utf8_is_recorded(): void
    {
        $listener = new class () {
            #[EventHandler('order.*')]
            public function hear(string $event): void
            {
            }
        };
        $ecotone = $this->application([$listener::class], [$listener]);
        $ecotone->publishEventWithRouting("order.\xC3\x28", 'x');

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => 'Hearing an order', 'location' => $this->location . '/after']);

        self::assertStringContainsString('order.', (string) json_encode($this->storedSnapshot('after', 'hearing-an-order')['trace'], JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function test_the_summary_of_a_change_repeated_in_every_batch_says_how_many_times_it_happens(): void
    {
        $baseline = __DIR__ . '/../../../../BuildFlow/baseline/';
        $file = 'tag-partitioned-projection-backfilled-asynchronously-in-two-batches.json';
        $after = json_decode((string) file_get_contents($baseline . $file), true, flags: JSON_THROW_ON_ERROR);
        $before = $after;
        $before['trace'] = array_values(array_filter($after['trace'], fn (array $step, int $position): bool => ! in_array($position, [5, 6, 11, 12], true), ARRAY_FILTER_USE_BOTH));
        foreach (['before' => $before, 'after' => $after] as $side => $snapshot) {
            mkdir($this->location . '/' . $side, recursive: true);
            copy($baseline . 'shared.json', $this->location . '/' . $side . '/shared.json');
            file_put_contents($this->location . '/' . $side . '/' . $file, json_encode($snapshot, JSON_THROW_ON_ERROR));
        }

        self::assertMatchesRegularExpression('/sends a part report to .*, 2 times\./', $this->comparison()['groups'][0]['summary']['now']);
    }

    public function test_a_new_process_with_the_same_steps_as_an_earlier_one_is_said_to_be_like_it_without_an_empty_exception(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $notify = fn (string $process): array => ['process' => $process, 'steps' => [
            ['depth' => 0, 'label' => 'receive notifications', 'hints' => []],
            ['depth' => 1, 'label' => 'handler class@anonymous::notify', 'hints' => []],
        ]];
        $this->replaceExecution('before', []);
        $this->replaceExecution('after', [$notify('receive notifications, class@anonymous::notify (1 of 2)'), $notify('receive notifications, class@anonymous::notify (2 of 2)')]);

        $presented = $this->whatRan($this->presented('before-after'));

        self::assertStringContainsString('Process `receive notifications, class@anonymous::notify (2 of 2)`: runs only now, with the same steps as `receive notifications, class@anonymous::notify (1 of 2)`.', $presented);
        self::assertStringNotContainsString('except:', $presented);
    }

    public function test_a_step_whose_only_changed_hint_is_a_detail_is_changed_and_says_how_many_detail_hints_changed(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $forward = fn (string $payload): array => [['process' => 'send command order.place', 'steps' => [
            ['depth' => 1, 'label' => 'forward to orders', 'hints' => [['key' => 'chosen by', 'value' => 'routing slip', 'shownInLines' => true], ['key' => 'payload', 'value' => $payload, 'shownInLines' => false]]],
        ]]];
        $this->replaceExecution('before', $forward('array'));
        $this->replaceExecution('after', $forward('string'));

        $markdown = $this->presented('before-after');
        $presented = $this->whatRan($markdown);

        self::assertSame('Now: process `send command order.place` runs the same steps, 1 of them with other hints.', $this->comparison()['groups'][0]['summary']['now']);
        self::assertStringContainsString("No scenario of 1 changes its message path. 1 runs the same steps with other hints.\n", $markdown);
        self::assertSame(['changed'], array_column($this->comparison()['groups'][0]['execution'], 'change'));
        self::assertStringContainsString("  forward to orders\n      ↳ chosen by: routing slip\n      ↳ 1 detail hint changed: payload; the json presentation shows its values\n", $presented);
        self::assertStringNotContainsString('payload: string', $presented);
    }

    public function test_a_new_consumer_run_shows_its_shared_preamble_once_and_a_second_alike_process_only_where_it_differs(): void
    {
        $baseline = __DIR__ . '/../../../../BuildFlow/baseline/';
        $file = 'tag-partitioned-projection-backfilled-asynchronously-in-two-batches.json';
        $after = json_decode((string) file_get_contents($baseline . $file), true, flags: JSON_THROW_ON_ERROR);
        $before = $after;
        $before['execution'] = array_values(array_filter($after['execution'], fn (array $process): bool => ! str_contains($process['process'], 'GatheringMessageHandler::handle')));
        foreach (['before' => $before, 'after' => $after] as $side => $snapshot) {
            mkdir($this->location . '/' . $side, recursive: true);
            copy($baseline . 'shared.json', $this->location . '/' . $side . '/shared.json');
            file_put_contents($this->location . '/' . $side . '/' . $file, json_encode($snapshot, JSON_THROW_ON_ERROR));
        }

        $presented = $this->whatRan($this->presented('before-after'));
        $second = substr($presented, (int) strpos($presented, 'Process `receive backfill, PartitionBatchExecutorHandler::executeBatch (2 of 2)`'));

        self::assertMatchesRegularExpression('/\n\+ +receive backfill\n\+ +↳ sent by: process `receive backfill, PartitionBatchExecutorHandler::executeBatch \(1 of 2\)`, the part report for part 1 of 2\n(\+ +↳ .*\n)*  … \d+ steps as in `receive backfill, PartitionBatchExecutorHandler::executeBatch \(1 of 2\)`\n/', $presented);
        self::assertStringContainsString('like `receive backfill, PartitionBatchExecutorHandler::executeBatch (1 of 2)` except', $second);
        self::assertLessThan(20, substr_count(substr($second, 0, (int) strpos($second . '```' . "\n\n", "```\n\n", 10)), "\n"));
    }

    public function test_every_step_in_every_baseline_is_a_label_of_at_most_90_characters_with_hints_from_one_vocabulary(): void
    {
        $vocabulary = ['chosen by', 'returns', 'outcome', 'failure', 'found', 'routing slip', 'kept', 'headers added', 'headers removed', 'headers changed', 'headers read', 'payload', 'sent by', 'taken by', 'stopped', 'collected', 'runs in', 'work in', 'range', 'identifiers', 'propagating', 'received with', 'retry', 'group', 'service'];
        $labels = [];
        $keys = [];
        $values = [];
        foreach (glob(__DIR__ . '/../../../../BuildFlow/baseline/*.json') as $file) {
            foreach (json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['execution'] ?? [] as $process) {
                foreach ($process['steps'] as $step) {
                    $labels[] = $step['label'];
                    foreach ($step['hints'] ?? [] as $hint) {
                        $keys[$hint['key']] = true;
                        $values[] = $hint['value'];
                        self::assertIsBool($hint['shownInLines']);
                    }
                }
            }
        }

        self::assertNotEmpty($labels);
        self::assertSame([], array_values(array_filter($labels, fn (string $label): bool => mb_strlen($label) > 90 || preg_match('/ so |which is|here without|; |Recorded[A-Z]/', $label) === 1)));
        self::assertSame([], array_values(array_diff(array_keys($keys), $vocabulary)));
        self::assertSame([], array_values(array_unique(array_filter($values, fn (string $value): bool => preg_match('/ so | did not | without failing |straight to |, nothing takes it/', $value) === 1))));
    }

    public function test_the_stored_snapshot_holds_each_step_as_its_depth_label_and_hints(): void
    {
        $this->record('Placing an order', 'after', $this->ordering());

        $steps = $this->storedSnapshot('after', 'placing-an-order')['execution'][1]['steps'];

        self::assertSame(['depth' => 1, 'label' => 'receive notifications', 'hints' => [['key' => 'sent by', 'value' => 'process 1, the event order.placed', 'shownInLines' => true]]], $steps[0]);
        self::assertSame(['depth' => 2, 'label' => 'take from channel notifications'], $steps[1]);
    }

    public function test_a_step_whose_hints_differ_is_one_changed_step_with_the_hint_before_and_now(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $routed = fn (string $channel): array => [['process' => 'send command order.place', 'steps' => [
            ['depth' => 1, 'label' => 'handler class@anonymous::place', 'hints' => []],
            ['depth' => 1, 'label' => 'forward to ' . $channel, 'hints' => [['key' => 'chosen by', 'value' => 'routing slip', 'shownInLines' => true], ['key' => 'routing slip', 'value' => $channel . ' → none', 'shownInLines' => true]]],
        ]]];
        $this->replaceExecution('before', $routed('orders'));
        $this->replaceExecution('after', $routed('orders'));
        $after = $routed('orders');
        $after[0]['steps'][1]['hints'][0]['value'] = 'output channel of class@anonymous::place';
        $this->replaceExecution('after', $after);

        $presented = $this->whatRan($this->presented('before-after'));

        self::assertStringContainsString("  forward to orders\n      ↳ routing slip: orders → none\n-     ↳ chosen by: routing slip\n+     ↳ chosen by: output channel of class@anonymous::place\n", $presented);
        self::assertStringNotContainsString('- forward to orders', $presented);
    }

    public function test_a_process_referred_to_by_number_is_compared_by_what_it_is_so_a_renumbered_process_stays_unchanged(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());
        $sender = fn (int $consumer): array => ['process' => 'send command order.place', 'steps' => [['depth' => 1, 'label' => 'send q', 'hints' => [['key' => 'taken by', 'value' => 'process ' . $consumer . ', a separate consumer run', 'shownInLines' => true]]]]];
        $gatherer = fn (int $sender): array => ['process' => 'receive q, GatheringMessageHandler::handle (part 1 of 1)', 'steps' => [['depth' => 1, 'label' => 'receive q', 'hints' => [
            ['key' => 'sent by', 'value' => 'process ' . $sender . ', the part report for part 1 of 1', 'shownInLines' => true],
            ['key' => 'work in', 'value' => 'process ' . $sender . ', part 1 of 1', 'shownInLines' => true],
        ]]]];
        $this->replaceExecution('before', [$sender(2), $gatherer(1)]);
        $this->replaceExecution('after', [['process' => 'initialize', 'steps' => [['depth' => 1, 'label' => 'handler class@anonymous::init', 'hints' => []]]], $sender(3), $gatherer(2)]);

        $execution = $this->comparison()['groups'][0]['execution'];

        self::assertSame(
            [
                ['process' => 'initialize', 'change' => 'added'],
                ['process' => 'send command order.place', 'change' => 'unchanged'],
                ['process' => 'receive q, GatheringMessageHandler::handle (part 1 of 1)', 'change' => 'unchanged'],
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

    private function record(string $scenario, string $side, object $ordering, bool $consumed = true): void
    {
        $ecotone = $this->application([$ordering::class], [$ordering]);
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
        $ecotone = $this->application([], []);

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
    private function application(array $classes, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [...$classes, BuildFlowConsoleCommands::class],
            [...$services, new BuildFlowConsoleCommands()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
