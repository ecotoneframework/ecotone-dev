<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Closure;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowStage;
use Ecotone\Test\LicenceTesting;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Test\Ecotone\BuildFlow\BuildFlowConsoleCommands;
use Test\Ecotone\BuildFlow\FlowScenarioRecorder;

/**
 * licence Apache-2.0
 * @internal
 */
final class BuildFlowStepTreeTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-build-flow-step-tree-' . bin2hex(random_bytes(6));
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

    public function test_an_unchanged_process_holds_its_whole_tree_in_a_closed_fold(): void
    {
        $unchanged = self::process('placing', [[1, 'handler place'], [2, 'direct channel orders']]);

        $whatRan = $this->whatRan(before: [$unchanged, self::changedBefore()], after: [$unchanged, self::changedAfter()]);

        self::assertStringContainsString("`placing`\n\n<details>\n<summary>Steps of process 1</summary>\n\n```diff\n  ⚙️ handler place\n    ➡️ direct channel orders\n```\n\n</details>", $whatRan);
        self::assertStringNotContainsString('… 2 unchanged steps', $whatRan);
    }

    public function test_a_changed_process_shows_its_whole_tree_open_with_new_steps_and_hints_as_added_lines(): void
    {
        $whatRan = $this->whatRan(before: [self::changedBefore()], after: [self::changedAfter()]);

        self::assertStringContainsString("<details open>\n<summary>Steps of process 1</summary>\n\n```diff\n  📬 receive shipments\n    ⚙️ handler ship\n      ↳ outcome: shipped\n+   ⚙️ handler notify\n+     ↳ chosen by: name shipment.shipped\n```\n\n</details>", $whatRan);
    }

    public function test_a_gone_step_and_a_gone_hint_are_removed_lines(): void
    {
        $whatRan = $this->whatRan(before: [self::changedAfter()], after: [self::process('shipping', [[1, 'receive shipments'], [2, 'handler ship']])]);

        self::assertStringContainsString("```diff\n  📬 receive shipments\n    ⚙️ handler ship\n-     ↳ outcome: shipped\n-   ⚙️ handler notify\n-     ↳ chosen by: name shipment.shipped\n```", $whatRan);
    }

    public function test_a_send_with_steps_under_it_folds_closed_as_its_channel_interceptors_when_nothing_in_it_changed(): void
    {
        $send = [[2, 'send backfill'], [3, 'before send OutboundSerializationChannelInterceptor::preSend'], [4, 'put on channel backfill']];

        $whatRan = $this->whatRan(
            before: [self::process('batch', [[1, 'receive backfill'], ...$send])],
            after: [self::process('batch', [[1, 'receive backfill'], ...$send, [2, 'acknowledge']])],
        );

        self::assertStringContainsString("<details>\n<summary>📨 <code>send backfill</code> · 2 steps of channel interceptors</summary>\n\n<blockquote>\n\n```diff\n  🪝 before send OutboundSerializationChannelInterceptor::preSend\n    📥 put on channel backfill\n```\n\n</blockquote>\n\n</details>", $whatRan);
    }

    public function test_a_fold_holding_a_new_step_is_open_and_marked_green(): void
    {
        $whatRan = $this->whatRan(
            before: [self::process('batch', [[1, 'receive backfill'], [2, 'send backfill'], [3, 'put on channel backfill']])],
            after: [self::process('batch', [[1, 'receive backfill'], [2, 'send backfill'], [3, 'before send TracingChannelInterceptor::preSend'], [4, 'put on channel backfill']])],
        );

        self::assertStringContainsString("<details open>\n<summary>🟢 📨 <code>send backfill</code> · 2 steps of channel interceptors</summary>", $whatRan);
        self::assertStringContainsString("+ 🪝 before send TracingChannelInterceptor::preSend\n", $whatRan);
    }

    public function test_a_fold_holding_only_gone_steps_is_open_and_marked_red(): void
    {
        $whatRan = $this->whatRan(
            before: [self::process('batch', [[1, 'receive backfill'], [2, 'send backfill'], [3, 'before send TracingChannelInterceptor::preSend'], [4, 'put on channel backfill']])],
            after: [self::process('batch', [[1, 'receive backfill'], [2, 'send backfill'], [3, 'put on channel backfill']])],
        );

        self::assertStringContainsString("<details open>\n<summary>🔴 📨 <code>send backfill</code> · 2 steps of channel interceptors</summary>", $whatRan);
    }

    public function test_interceptors_wrapping_one_another_fold_into_one_list_and_what_they_wrap_follows_where_the_first_stood(): void
    {
        $steps = [[1, 'gateway CommandBus::sendWithRouting'], [2, 'around TracingInterceptor::traceCommandBus'], [3, 'around MessageHeadersPropagatorInterceptor::storeHeaders'], [4, 'span send command order.place'], [5, 'handler place'], [6, 'direct channel orders']];

        $whatRan = $this->whatRan(before: [self::process('placing', $steps)], after: [self::process('placing', [...$steps, [2, 'acknowledge']])]);

        self::assertStringContainsString("<details>\n<summary>🪝 2 interceptors around ⚙️ <code>handler place</code></summary>\n\n<blockquote>\n\n```diff\n  🪝 around TracingInterceptor::traceCommandBus\n    🪝 around MessageHeadersPropagatorInterceptor::storeHeaders\n      🔎 span send command order.place\n```\n\n</blockquote>\n\n</details>\n\n```diff\n  ⚙️ handler place\n    ➡️ direct channel orders\n+ ✅ acknowledge\n```", $whatRan);
    }

    public function test_a_gateway_call_with_steps_under_it_folds_as_the_steps_inside(): void
    {
        $whatRan = $this->whatRan(
            before: [self::process('placing', [[1, 'handler place'], [2, 'gateway EventBus::publish'], [3, 'direct channel events'], [3, 'handler notify']])],
            after: [self::process('placing', [[1, 'handler place'], [2, 'gateway EventBus::publish'], [3, 'direct channel events'], [3, 'handler notify'], [2, 'acknowledge']])],
        );

        self::assertStringContainsString("<details>\n<summary>🚪 <code>gateway EventBus::publish</code> · 2 steps inside</summary>\n\n<blockquote>\n\n```diff\n  ➡️ direct channel events\n  ⚙️ handler notify\n```\n\n</blockquote>\n\n</details>", $whatRan);
    }

    public function test_a_step_with_six_steps_under_it_among_siblings_folds_by_size(): void
    {
        $inside = array_map(fn (int $number): array => [3, 'direct channel part' . $number], range(1, 6));
        $steps = [[1, 'receive backfill'], [2, 'handler executeBatch'], ...$inside, [2, 'handler report']];

        $whatRan = $this->whatRan(before: [self::process('batch', $steps)], after: [self::process('batch', [...$steps, [2, 'acknowledge']])]);

        self::assertStringContainsString("<details>\n<summary>⚙️ <code>handler executeBatch</code> · 6 steps inside</summary>", $whatRan);
    }

    public function test_four_unchanged_steps_in_a_row_fold_and_three_are_printed(): void
    {
        $four = array_map(fn (int $number): array => [2, 'direct channel first' . $number], range(1, 4));
        $three = array_map(fn (int $number): array => [2, 'direct channel second' . $number], range(1, 3));
        $before = [[1, 'receive backfill'], ...$four, [2, 'handler executeBatch'], ...$three];
        $after = [[1, 'receive backfill'], ...$four, [2, 'handler executeBatchNow'], ...$three];

        $whatRan = $this->whatRan(before: [self::process('batch', $before)], after: [self::process('batch', $after)]);

        self::assertStringContainsString("<details>\n<summary>… 4 unchanged steps</summary>", $whatRan);
        self::assertStringContainsString("- ⚙️ handler executeBatch\n+ ⚙️ handler executeBatchNow\n  ➡️ direct channel second1\n  ➡️ direct channel second2\n  ➡️ direct channel second3\n", $whatRan);
    }

    public function test_folds_nest_in_quotes_at_most_six_deep(): void
    {
        $steps = [];
        for ($depth = 1; $depth <= 9; $depth++) {
            $steps[] = [$depth, 'forward to channel' . $depth];
        }
        $steps[] = [10, 'handler deepest'];

        $whatRan = $this->whatRan(before: [self::process('deep', $steps)], after: [self::process('deep', [...$steps, [11, 'handler added']])]);

        self::assertSame(9, substr_count($whatRan, '<summary>🟢 🔀 <code>forward to channel'));
        self::assertSame(6, self::deepestNestingOf($whatRan, 'blockquote'));
        self::assertBalancedWithBlankLinesAround($whatRan);
    }

    public function test_past_six_quotes_the_steps_keep_their_indentation_from_the_last_quote(): void
    {
        $steps = [];
        for ($depth = 1; $depth <= 9; $depth++) {
            $steps[] = [$depth, 'forward to channel' . $depth];
        }
        $steps[] = [10, 'handler deepest'];

        $whatRan = $this->whatRan(before: [self::process('deep', $steps)], after: [self::process('deep', [...$steps, [11, 'handler added']])]);

        self::assertStringContainsString("```diff\n        ⚙️ handler deepest\n+         ⚙️ handler added\n```", $whatRan);
    }

    public function test_two_interceptor_runs_in_a_row_each_name_the_last_step_they_wrap(): void
    {
        $steps = [[1, 'receive backfill'], [2, 'around A::first'], [3, 'around B::second'], [4, 'around C::third'], [5, 'around D::fourth'], [6, 'handler executeBatch'], [6, 'release collected message to backfill'], [4, 'acknowledge']];

        $whatRan = $this->whatRan(before: [self::process('batch', $steps)], after: [self::process('batch', [...$steps, [2, 'handler added']])]);

        self::assertStringContainsString('<summary>🪝 2 interceptors around the next 5 steps, through ✅ <code>acknowledge</code></summary>', $whatRan);
        self::assertStringContainsString('<summary>🪝 2 interceptors around the next 2 steps, through 📨 <code>release collected message to backfill</code></summary>', $whatRan);
    }

    public function test_a_fold_of_a_step_that_is_itself_new_says_new_and_one_of_a_gone_step_says_gone(): void
    {
        $send = [[2, 'send backfill'], [3, 'put on channel backfill']];

        $added = $this->whatRan(before: [self::process('batch', [[1, 'receive backfill'], [2, 'acknowledge']])], after: [self::process('batch', [[1, 'receive backfill'], ...$send, [2, 'acknowledge']])]);
        $removed = $this->whatRan(before: [self::process('batch', [[1, 'receive backfill'], ...$send, [2, 'acknowledge']])], after: [self::process('batch', [[1, 'receive backfill'], [2, 'acknowledge']])]);

        self::assertStringContainsString("<details open>\n<summary>🟢 new · 📨 <code>send backfill</code> · 1 step of channel interceptors</summary>", $added);
        self::assertStringContainsString("<details open>\n<summary>🔴 gone · 📨 <code>send backfill</code> · 1 step of channel interceptors</summary>", $removed);
    }

    public function test_a_fold_where_nothing_changed_does_not_fold_its_unchanged_steps_again(): void
    {
        $send = [[2, 'send backfill'], ...array_map(fn (int $number): array => [3, 'before send Interceptor' . $number . '::preSend'], range(1, 5))];

        $whatRan = $this->whatRan(before: [self::process('batch', [[1, 'receive backfill'], ...$send])], after: [self::process('batch', [[1, 'receive backfill'], ...$send, [2, 'acknowledge']])]);

        self::assertStringContainsString('<summary>📨 <code>send backfill</code> · 5 steps of channel interceptors</summary>', $whatRan);
        self::assertStringNotContainsString('… 5 unchanged steps', $whatRan);
    }

    public function test_a_process_repeating_another_shows_its_own_tree(): void
    {
        $steps = [[1, 'receive backfill'], [2, 'handler gather'], [2, 'acknowledge']];

        $whatRan = $this->whatRan(before: [self::changedBefore()], after: [self::changedAfter(), self::process('gathering (part 1 of 2)', $steps), self::process('gathering (part 2 of 2)', $steps)]);

        self::assertSame(2, substr_count($whatRan, "```diff\n+ 📬 receive backfill\n+   ⚙️ handler gather\n+   ✅ acknowledge\n```"));
        self::assertStringNotContainsString('Like process', $whatRan);
        self::assertStringNotContainsString('steps as in process', $whatRan);
    }

    public function test_a_process_that_no_longer_runs_shows_its_tree_as_removed_lines(): void
    {
        $whatRan = $this->whatRan(before: [self::changedBefore(), self::process('notifying', [[1, 'receive notifications'], [2, 'handler notify']])], after: [self::changedAfter()]);

        self::assertStringContainsString("<details open>\n<summary>Steps of process 2 before</summary>\n\n```diff\n- 📬 receive notifications\n-   ⚙️ handler notify\n```\n\n</details>", $whatRan);
    }

    public function test_what_ran_opens_with_one_legend_line(): void
    {
        $whatRan = $this->whatRan(before: [self::changedBefore()], after: [self::changedAfter()]);

        self::assertStringStartsWith("#### What ran, process by process\n\n🟢 a fold holding something new or changed, open; 🔴 a fold holding only gone steps, open; `🟢 new ·` / `🔴 gone ·` the folded step itself is new / gone; `+` new, `-` gone; each bar is one level deeper, and past six bars the indentation shows the depth.\n\n**Flow · process 1 of 1**", $whatRan);
    }

    public function test_a_backtick_in_a_title_or_a_label_does_not_split_its_inline_code(): void
    {
        $whatRan = $this->whatRan(before: [self::process('send `odd`', [[1, 'receive q']])], after: [self::process('send `odd`', [[1, 'receive q'], [2, 'handler `odd`']])]);

        self::assertStringContainsString("\n`` send `odd` ``\nNew: `` handler `odd` ``.\n", $whatRan);
    }

    public function test_steps_moved_under_two_new_steps_are_counted_per_new_step(): void
    {
        $before = [[1, 'handler prepare'], [2, 'entrypoint sends to first'], [3, 'direct channel first'], [2, 'entrypoint sends to second'], [3, 'direct channel second']];
        $after = [[1, 'handler prepare'], [2, 'dispatch first'], [3, 'entrypoint sends to first'], [4, 'direct channel first'], [2, 'dispatch second'], [3, 'entrypoint sends to second'], [4, 'direct channel second']];

        $whatRan = $this->whatRan(before: [self::process('preparing', $before)], after: [self::process('preparing', $after)]);

        self::assertStringContainsString('`entrypoint sends to first` (2 steps with what runs inside it) now runs under the new `dispatch first`. `entrypoint sends to second` (2 steps with what runs inside it) now runs under the new `dispatch second`.', $whatRan);
    }

    public function test_every_icon_the_glossary_names_is_the_one_the_tree_draws_for_that_kind_of_step(): void
    {
        $glossary = (string) file_get_contents(__DIR__ . '/../../../../../../../.agents/skills/framework-dev-environment/references/workflow.md');
        preg_match('/^\| the icons \| (.*) \|$/m', $glossary, $row);
        self::assertNotEmpty($row);
        $documented = [];
        foreach (explode('; ', explode('. ', $row[1])[0]) as $entry) {
            [$icon, $kinds] = explode(' ', $entry, 2);
            preg_match_all('/`([^`]+)`/', $kinds, $named);
            foreach ($named[1] as $kind) {
                $documented[$kind] = $icon;
            }
        }

        $whatRan = $this->whatRan(before: [self::changedBefore()], after: [self::changedAfter(), self::process('every kind', array_map(fn (string $kind): array => [1, $kind . ' x'], array_keys($documented)))]);

        $drawn = [];
        foreach (array_keys($documented) as $kind) {
            preg_match('/^\+ (\S+) ' . preg_quote($kind, '/') . ' x$/mu', $whatRan, $line);
            $drawn[$kind] = $line[1] ?? 'none';
        }
        self::assertSame($documented, $drawn);
        self::assertGreaterThanOrEqual(30, count($documented));
    }

    public function test_every_fence_and_html_block_is_balanced_and_has_a_blank_line_around_it(): void
    {
        $send = [[3, 'send backfill'], [4, 'before send A::preSend'], [5, 'put on channel backfill']];
        $chain = [[2, 'around A::one'], [3, 'around B::two'], [4, 'span process x']];
        $steps = [[1, 'gateway CommandBus::send'], ...$chain, [5, 'handler x'], ...array_map(fn (array $step): array => [$step[0] + 3, $step[1]], $send), [6, 'direct channel a'], [6, 'direct channel b'], [6, 'direct channel c'], [6, 'direct channel d']];

        $whatRan = $this->whatRan(before: [self::process('mixed', $steps)], after: [self::process('mixed', [...$steps, [6, 'handler added']])]);

        self::assertBalancedWithBlankLinesAround($whatRan);
    }

    private static function assertBalancedWithBlankLinesAround(string $markdown): void
    {
        $lines = explode("\n", $markdown);
        $inFence = false;
        $open = [];
        foreach ($lines as $position => $line) {
            $before = $lines[$position - 1] ?? '';
            $after = $lines[$position + 1] ?? '';
            if (str_starts_with($line, '```')) {
                self::assertSame('', $inFence ? $after : $before);
                $inFence = ! $inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match('/^<(details|blockquote)( open)?>$/', $line, $tag) === 1) {
                $open[] = $tag[1];
                self::assertSame('', $before);
                self::assertTrue($tag[1] === 'details' ? str_starts_with($after, '<summary>') : $after === '');
            } elseif (preg_match('/^<\/(details|blockquote)>$/', $line, $tag) === 1) {
                self::assertSame($tag[1], array_pop($open));
                self::assertSame(['', ''], [$before, $after]);
            } elseif (str_starts_with($line, '<summary>')) {
                self::assertSame('', $after);
            }
        }
        self::assertFalse($inFence);
        self::assertSame([], $open);
    }

    private static function deepestNestingOf(string $markdown, string $tag): int
    {
        $depth = 0;
        $deepest = 0;
        foreach (explode("\n", $markdown) as $line) {
            if ($line === "<{$tag}>") {
                $deepest = max($deepest, ++$depth);
            } elseif ($line === "</{$tag}>") {
                $depth--;
            }
        }

        return $deepest;
    }

    /**
     * @return array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}
     */
    private static function changedBefore(): array
    {
        return self::process('shipping', [[1, 'receive shipments'], [2, 'handler ship', ['outcome' => 'shipped']]]);
    }

    /**
     * @return array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}
     */
    private static function changedAfter(): array
    {
        return self::process('shipping', [[1, 'receive shipments'], [2, 'handler ship', ['outcome' => 'shipped']], [2, 'handler notify', ['chosen by' => 'name shipment.shipped']]]);
    }

    /**
     * @param list<array{0: int, 1: string, 2?: array<string, string>}> $steps
     * @return array{process: string, steps: list<array{depth: int, label: string, hints: list<array{key: string, value: string, shownInLines: bool}>}>}
     */
    private static function process(string $title, array $steps): array
    {
        return ['process' => $title, 'steps' => array_map(fn (array $step): array => [
            'depth' => $step[0],
            'label' => $step[1],
            'hints' => array_map(fn (string $key, string $value): array => ['key' => $key, 'value' => $value, 'shownInLines' => true], array_keys($step[2] ?? []), array_values($step[2] ?? [])),
        ], $steps)];
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    private function whatRan(array $before, array $after): string
    {
        $placing = new class () {
            #[CommandHandler('order.place')]
            public function place(): void
            {
            }
        };
        foreach (['before' => $before, 'after' => $after] as $side => $execution) {
            $this->recordAs('Placing an order', $side, [$placing::class], [$placing], [SimpleMessageChannelBuilder::createQueueChannel('notifications')], fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', []));
            $file = $this->location . '/' . $side . '/placing-an-order.json';
            $snapshot = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $snapshot['execution'] = $execution;
            file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
        }
        $ecotone = $this->application([], []);
        $ecotone->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after', 'presentation' => 'build']);
        $presented = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
        $start = (int) strpos($presented, '#### What ran, process by process');
        $end = strpos($presented, "#### The message's path", $start);

        return substr($presented, $start, $end === false ? null : $end - $start);
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     */
    private function application(array $classes, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [...$classes],
            [...$services, new BuildFlowConsoleCommands()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     * @param list<object> $extensionObjects
     */
    private function recordAs(string $scenario, string $side, array $classes, array $services, array $extensionObjects, Closure $run): void
    {
        $snapshot = FlowScenarioRecorder::snapshotOf(new FlowScenario(name: $scenario, classesToResolve: $classes, services: $services, extensionObjects: $extensionObjects, stages: [FlowStage::when($run)]));
        $directory = $this->location . '/' . $side;
        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }
        file_put_contents($directory . '/' . $snapshot->fileName(), $snapshot->toJson());
    }
}
