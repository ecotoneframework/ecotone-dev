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
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Test\Ecotone\BuildFlow\BuildFlowConsoleCommands;

/**
 * licence Apache-2.0
 * @internal
 */
final class BuildFlowPresentationTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-build-flow-presentation-' . bin2hex(random_bytes(6));
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

    public function test_before_after_pair_draws_the_changed_region_twice_and_lists_the_steps_twice(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('before-after');

        self::assertStringStartsWith("## Message flow: what changed\n\n1 of 1 scenario changes its message path.\n\n### Build: what changes\n\n```text\nMessagingSystem\n", $markdown);
        self::assertStringContainsString("\n### `Placing an order`\n\nBefore: `send event order.placed` was followed directly by `route whenOrderWasPlaced`.\n\nNow: after `send event order.placed`, it is routed to `audit.target`; `event handler audit` handles it.\n\n#### Wiring\n\nBefore:\n\n```mermaid\nflowchart LR\n", $markdown);
        [$before, $after] = $this->mermaidBlocksOf($markdown);
        self::assertStringNotContainsString('audit', $before);
        self::assertStringContainsString('Event Handler<br/>audit', $after);
        self::assertStringContainsString('Channel<br/>audit.target', $after);
        self::assertStringNotContainsString('classDef added', $after);
        self::assertStringContainsString(
            "#### Traced spans\n\nBefore:\n\n```text\nsend command order.place\n  process command handler place\n    send event order.placed\n      route whenOrderWasPlaced → whenOrderWasPlaced.target\n  send notifications\n    receive notifications\n      process event handler whenPlaced\n```\n\n"
            . "After:\n\n```text\nsend command order.place\n  process command handler place\n    send event order.placed\n      route audit → audit.target   ← new\n        process event handler audit   ← new\n      route whenOrderWasPlaced → whenOrderWasPlaced.target\n  send notifications\n    receive notifications\n      process event handler whenPlaced\n```\n",
            $markdown,
        );
    }

    public function test_marked_diagram_colours_what_was_added_and_lists_added_and_removed_steps_once(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('marked');

        $diagrams = $this->mermaidBlocksOf($markdown);
        self::assertCount(1, $diagrams);
        self::assertStringContainsString('    classDef added fill:#dafbe1,stroke:#2da44e,color:#1f2328', $diagrams[0]);
        self::assertStringContainsString('Event Handler<br/>audit', $diagrams[0]);
        self::assertStringContainsString(
            "#### Traced spans\n\n```diff\n send command order.place\n   process command handler place\n     send event order.placed\n+      route audit → audit.target\n+        process event handler audit\n       route whenOrderWasPlaced → whenOrderWasPlaced.target\n   send notifications\n     receive notifications\n       process event handler whenPlaced\n```\n",
            $markdown,
        );
    }

    public function test_marked_diagram_lists_a_removed_step_when_the_comparison_runs_the_other_way(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('marked', from: 'after', to: 'before');

        self::assertStringContainsString('    classDef removed fill:#ffebe9,stroke:#cf222e,color:#1f2328,stroke-dasharray:5 5', $markdown);
        self::assertStringContainsString("-      route audit → audit.target\n-        process event handler audit\n", $markdown);
    }

    public function test_message_path_follows_the_message_before_and_after_as_one_sequence_diagram_with_sentences(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('path');

        $diagrams = $this->mermaidBlocksOf($markdown);
        self::assertCount(1, $diagrams);
        self::assertStringStartsWith("sequenceDiagram\n", $diagrams[0]);
        self::assertSame(['    rect rgba(248, 81, 73, 0.15)', '    rect rgba(46, 160, 67, 0.15)'], array_values(array_filter(explode("\n", $diagrams[0]), fn (string $line): bool => str_starts_with($line, '    rect '))));
        [, $before, $after] = preg_split('/^    rect rgba\(.*\)$/m', $diagrams[0]);
        self::assertStringContainsString('Before', $before);
        self::assertStringNotContainsString('audit', $before);
        self::assertStringContainsString('After', $after);
        self::assertMatchesRegularExpression('/^    p\d+->>p\d+: \+ handles$/m', $after);
        self::assertMatchesRegularExpression('/^    participant p\d+ as event handler audit$/m', $diagrams[0]);
        self::assertStringContainsString("Before: `send event order.placed` was followed directly by `route whenOrderWasPlaced`.\n\nNow: after `send event order.placed`, it is routed to `audit.target`; `event handler audit` handles it.\n\n#### The message's path\n", $markdown);
    }

    public function test_the_message_path_of_a_wiring_change_draws_only_the_sequence_diagram(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $diagrams = $this->mermaidBlocksOf($this->presentation('path'));

        self::assertCount(1, $diagrams);
        self::assertStringStartsWith("sequenceDiagram\n", $diagrams[0]);
    }

    public function test_the_build_presentation_draws_the_message_path_before_the_changed_wiring_collapsed_under_it(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        $diagrams = $this->mermaidBlocksOf($markdown);
        self::assertCount(2, $diagrams);
        self::assertStringStartsWith("sequenceDiagram\n", $diagrams[0]);
        self::assertStringStartsWith('flowchart', $diagrams[1]);
        self::assertStringContainsString('audit', $diagrams[1]);
        $path = strpos($markdown, "#### The message's path\n\n```mermaid\nsequenceDiagram");
        $collapsed = strpos($markdown, "<details><summary>Marked wiring and traced spans</summary>\n\n#### Wiring\n");
        $wiringDiagram = strpos($markdown, "```mermaid\nflowchart", (int) $collapsed);
        self::assertIsInt($path);
        self::assertIsInt($collapsed);
        self::assertIsInt($wiringDiagram);
        self::assertLessThan($collapsed, $path);
        self::assertLessThan(strpos($markdown, "</details>\n", $wiringDiagram), $wiringDiagram);
    }

    public function test_the_build_presentation_lists_every_process_in_run_order_with_its_status_and_step_count(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        self::assertMatchesRegularExpression('/^\*\*Flow · process 1 of 2\*\* · \d+ new steps, 1 step gone · \d+ → \d+ steps\n`send command order\.place, class@anonymous::place`\nNew: `route to audit\.target, whenOrderWasPlaced\.target` in place of `route to whenOrderWasPlaced\.target`, and `span route audit audit\.target`\.$/m', $markdown);
        self::assertMatchesRegularExpression('/^\*\*Sub-flow · process 2 of 2\*\* · sent by process 1 · unchanged · (\d+) steps\n`receive notifications, class@anonymous::whenPlaced`\n\n… \1 unchanged steps$/m', $markdown);
    }

    public function test_the_build_presentation_names_every_changed_scenario_with_how_many_of_its_processes_changed_right_under_the_summary(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        self::assertStringStartsWith("## Message flow: what changed\n\n1 of 1 scenario changes its message path.\n\n- `Placing an order`: 1 of 2 processes changed\n\n", $markdown);
    }

    public function test_the_build_presentation_lists_a_process_that_no_longer_runs_with_its_number_before(): void
    {
        $synchronous = new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }
        };
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $synchronous);

        $markdown = $this->presentation('build');

        self::assertMatchesRegularExpression('/^\*\*Sub-flow · process 2 of 2 before\*\* · no longer runs · (\d+) steps\n`receive notifications, class@anonymous::whenPlaced`\n\n… \1 steps no longer run$/m', $markdown);
    }

    public function test_the_build_presentation_lists_a_process_that_runs_only_now_at_its_place_in_run_order(): void
    {
        $this->record('Placing an order', 'before', $this->orderingNotifiedSynchronously());
        $this->record('Placing an order', 'after', $this->ordering());

        $markdown = $this->presentation('build');

        self::assertMatchesRegularExpression('/\*\*Flow · process 1 of 2\*\*[^\n]*\n`send command order\.place, class@anonymous::place`\n.*?\n\n\*\*Sub-flow · process 2 of 2\*\* · sent by process 1 · runs only now · \d+ steps\n`receive notifications, class@anonymous::whenPlaced`\n/s', $markdown);
    }

    public function test_the_build_presentation_lists_a_process_that_no_longer_runs_where_it_ran_before(): void
    {
        $before = new class () {
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

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'auditOrder')]
            public function audit(): void
            {
            }
        };
        $after = new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'auditOrder')]
            public function audit(): void
            {
            }
        };
        $this->record('Placing an order', 'before', $before);
        $this->record('Placing an order', 'after', $after);

        $markdown = $this->presentation('build');

        $headings = [];
        preg_match_all('/^\*\*(?:Flow|Sub-flow) · process \d+ of \d+(?: before)?\*\*[^\n]*\n`([^`]+)`$/m', $markdown, $headings);
        self::assertSame(['send command order.place, class@anonymous::place', 'receive notifications, class@anonymous::whenPlaced', 'receive notifications, class@anonymous::audit'], $headings[1]);
        self::assertStringContainsString('**Sub-flow · process 2 of 3 before** · no longer runs', $markdown);
    }

    public function test_the_build_presentation_shows_a_changed_step_under_every_step_it_runs_in_and_collapses_the_rest(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        preg_match('/<details open><summary>Steps of process 1<\/summary>\n\n```diff\n(.*?)```\n\n<\/details>/s', $markdown, $tree);
        self::assertNotEmpty($tree);
        $lines = explode("\n", rtrim($tree[1]));
        $added = array_search('+ ' . str_repeat('  ', $this->depthOf($lines, 'route to audit.target, whenOrderWasPlaced.target')) . 'route to audit.target, whenOrderWasPlaced.target', $lines, true);
        self::assertIsInt($added);
        self::assertMatchesRegularExpression('/^  \S/', $lines[0]);
        for ($depth = $this->depthOf($lines, 'route to audit.target, whenOrderWasPlaced.target') - 1; $depth >= 0; $depth--) {
            $ancestor = null;
            for ($position = $added - 1; $position >= 0; $position--) {
                if (preg_match('/^  ' . str_repeat('  ', $depth) . '(?! )(?!… )(?!↳ )\S/', $lines[$position]) === 1) {
                    $ancestor = $lines[$position];
                    break;
                }
            }
            self::assertNotNull($ancestor);
        }
        self::assertContains('+ ' . str_repeat('  ', $this->depthOf($lines, 'route to audit.target, whenOrderWasPlaced.target') + 1) . '↳ chosen by: name order.placed', $lines);
        self::assertMatchesRegularExpression('/^  ( {2})*… \d+ unchanged steps?$/m', $tree[1]);
    }

    public function test_the_build_presentation_puts_what_ran_before_the_message_path_and_the_collapsed_wiring(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        $whatRan = strpos($markdown, "#### What ran, process by process\n");
        $path = strpos($markdown, "#### The message's path\n");
        $wiring = strpos($markdown, '<details><summary>Marked wiring and traced spans</summary>');
        self::assertIsInt($whatRan);
        self::assertLessThan($path, $whatRan);
        self::assertLessThan($wiring, $path);
    }

    public function test_every_presentation_keeps_each_diagram_within_fifteen_nodes_and_says_what_it_left_out(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithManySubscribers());

        foreach (['before-after', 'marked'] as $presentation) {
            $diagrams = $this->mermaidBlocksOf($this->presentation($presentation));
            self::assertGreaterThan(1, count($diagrams));
            foreach ($diagrams as $diagram) {
                self::assertLessThanOrEqual(15, preg_match_all('/^\s+n\d+[\[\(\{>]/m', $diagram));
            }
        }
        self::assertLessThanOrEqual(15, preg_match_all('/^    participant /m', $this->mermaidBlocksOf($this->presentation('path'))[0]));
    }

    public function test_scenarios_with_the_same_change_share_one_section_that_lists_them(): void
    {
        $this->recordAnOrderThatGainsAnAudit('First ordering');
        $this->recordAnOrderThatGainsAnAudit('Second ordering');

        $markdown = $this->presentation('marked');

        self::assertStringContainsString("2 of 2 scenarios change their message path.\n\n### Build: what changes\n", $markdown);
        self::assertStringContainsString("\n### 2 scenarios with the same change\n\nApplies to: `First ordering`, `Second ordering`.\n\n", $markdown);
        self::assertCount(1, $this->mermaidBlocksOf($markdown));
    }

    public function test_snapshots_without_a_difference_are_presented_as_no_change(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());

        foreach (['before-after', 'marked', 'path'] as $presentation) {
            self::assertSame("## Message flow: what changed\n\nThe 1 scenario did not change its wiring, its build or its traced spans.\n", $this->presentation($presentation));
        }
    }

    public function test_compare_without_a_presentation_prints_the_before_after_pair_as_markdown(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $ecotone = $this->application([], []);
        $ecotone->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after']);

        self::assertSame($this->presentation('before-after'), $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_an_unknown_presentation_is_refused_naming_the_ones_that_exist(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $this->expectExceptionMessage('ecotone:build-flow:compare was asked for presentation `D`, which it cannot produce. Pass --presentation=before-after (the default) for a before/after pair of diagrams and step lists, --presentation=marked for one diagram with the changes marked and one step list, --presentation=path for the message\'s path as a sequence diagram with what changed in sentences, --presentation=build for the build, the message\'s path and the marked wiring collapsed, or --presentation=json for the raw comparison.');

        $this->presentation('D');
    }

    private function recordAnOrderThatGainsAnAudit(string $scenario): void
    {
        $this->record($scenario, 'before', $this->ordering());
        $this->record($scenario, 'after', $this->orderingWithAudit());
    }

    private function orderingNotifiedSynchronously(): object
    {
        return new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', []);
            }

            #[EventHandler('order.placed', endpointId: 'whenOrderWasPlaced')]
            public function whenPlaced(): void
            {
            }
        };
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

            #[EventHandler('order.placed', endpointId: 'whenPlacedOne')]
            public function whenPlacedOne(): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'whenPlacedTwo')]
            public function whenPlacedTwo(): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'whenPlacedThree')]
            public function whenPlacedThree(): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'whenPlacedFour')]
            public function whenPlacedFour(): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'whenPlacedFive')]
            public function whenPlacedFive(): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'whenPlacedSix')]
            public function whenPlacedSix(): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'whenPlacedSeven')]
            public function whenPlacedSeven(): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'whenPlacedEight')]
            public function whenPlacedEight(): void
            {
            }

            #[EventHandler('order.placed', endpointId: 'whenPlacedNine')]
            public function whenPlacedNine(): void
            {
            }
        };
    }

    private function record(string $scenario, string $side, object $ordering): void
    {
        $ecotone = $this->application([$ordering::class], [$ordering]);
        $ecotone->sendCommandWithRouting('order.place', []);
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => $scenario, 'location' => $this->location . '/' . $side]);
    }

    private function presentation(string $presentation, string $from = 'before', string $to = 'after'): string
    {
        $ecotone = $this->application([], []);

        $ecotone->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/' . $from, 'to' => $this->location . '/' . $to, 'presentation' => $presentation]);

        return $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
    }

    /**
     * @return list<string>
     */
    private function mermaidBlocksOf(string $markdown): array
    {
        preg_match_all('/```mermaid\n(.*?)```/s', $markdown, $blocks);

        return $blocks[1];
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

    private function depthOf(array $lines, string $label): int
    {
        foreach ($lines as $line) {
            if (preg_match('/^[+\- ] ((?:  )*)' . preg_quote($label, '/') . '$/', $line, $match) === 1) {
                return intdiv(strlen($match[1]), 2);
            }
        }

        return -1;
    }
}
