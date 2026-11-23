<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Closure;
use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Poller;
use Ecotone\Api\Attribute\Scheduled;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
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

    public function test_the_marked_wiring_colours_what_was_added_and_lists_added_and_removed_steps_once(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        $diagrams = $this->mermaidBlocksOf($markdown);
        self::assertCount(2, $diagrams);
        self::assertStringContainsString('    classDef added fill:#dafbe1,stroke:#2da44e,color:#1f2328', $diagrams[1]);
        self::assertStringContainsString('Event Handler<br/>audit', $diagrams[1]);
        self::assertStringContainsString(
            "#### Traced spans\n\n```diff\n send command order.place\n   process command handler place\n     send event order.placed\n+      route audit → audit.target\n+        process event handler audit\n       route whenOrderWasPlaced → whenOrderWasPlaced.target\n   send notifications\n     receive notifications\n       process event handler whenPlaced\n```\n",
            $markdown,
        );
    }

    public function test_the_marked_wiring_lists_a_removed_step_when_the_comparison_runs_the_other_way(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build', from: 'after', to: 'before');

        self::assertStringContainsString('    classDef removed fill:#ffebe9,stroke:#cf222e,color:#1f2328,stroke-dasharray:5 5', $markdown);
        self::assertStringContainsString("-      route audit → audit.target\n-        process event handler audit\n", $markdown);
    }

    public function test_message_path_draws_every_hand_over_once_as_one_flow_diagram_with_the_new_ones_green(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        $diagrams = $this->mermaidBlocksOf($markdown);
        self::assertStringStartsWith("flowchart LR\n", $diagrams[0]);
        self::assertMatchesRegularExpression('/^  (p\d+)\["event handler audit"\]:::new$/m', $diagrams[0]);
        self::assertMatchesRegularExpression('/^  p\d+ -->\|"\+ handles"\| p\d+$/m', $diagrams[0]);
        self::assertMatchesRegularExpression('/^  p\d+ -->\|"handles"\| p\d+$/m', $diagrams[0]);
        self::assertSame(count(array_unique(preg_grep('/^  p\d+ -/', explode("\n", $diagrams[0])))), count(preg_grep('/^  p\d+ -/', explode("\n", $diagrams[0]))));
        self::assertMatchesRegularExpression('/^  classDef new fill:#2ea04333,stroke:#2ea043,stroke-width:2px$/m', $diagrams[0]);
        self::assertMatchesRegularExpression('/^  linkStyle \d+ stroke:#2ea043,stroke-width:2\.5px$/m', $diagrams[0]);
        self::assertStringContainsString("\n### `Placing an order`\n\nBefore: `send event order.placed` was followed directly by `route whenOrderWasPlaced`.\n\nNow: after `send event order.placed`, it is routed to `audit.target`; `event handler audit` handles it.\n\n", $markdown);
        self::assertStringContainsString("#### The message's path\n\nGreen marks what happens only now and red what happened only before: a green or red arrow is such a hand-over, a green or red box a step only such hand-overs reach; a plain arrow or box is there before and now, and a dashed arrow is taken from the wiring because no span is recorded.\n\n```mermaid\nflowchart LR\n", $markdown);
    }

    public function test_message_path_draws_a_gone_hand_over_red_after_the_ones_that_happen_now(): void
    {
        $this->record('Placing an order', 'before', $this->orderingWithAudit());
        $this->record('Placing an order', 'after', $this->ordering());

        [$diagram] = $this->mermaidBlocksOf($this->presentation('build'));

        $edges = array_values(preg_grep('/^  p\d+ -/', explode("\n", $diagram)));
        self::assertMatchesRegularExpression('/^  p\d+ -->\|"- handles"\| p\d+$/', $edges[count($edges) - 1]);
        self::assertMatchesRegularExpression('/^  (p\d+)\["event handler audit"\]:::gone$/m', $diagram);
        self::assertMatchesRegularExpression('/^  linkStyle ' . (count($edges) - 1) . ' stroke:#f85149,stroke-width:2\.5px$/m', $diagram);
    }

    public function test_message_path_keeps_quotes_pipes_brackets_backticks_and_hashes_of_a_label_from_breaking_the_diagram(): void
    {
        $hostile = 'order"|#[x]`y`';
        $this->recordAnEventCalled($hostile, 'before', withAudit: false);
        $this->recordAnEventCalled($hostile, 'after', withAudit: true);

        [$diagram] = $this->mermaidBlocksOf($this->presentation('build'));

        foreach (explode("\n", rtrim($diagram)) as $line) {
            if (preg_match('/^  p\d+ /', $line) === 1) {
                self::assertMatchesRegularExpression('/^  p\d+ -\.?->\|"[^"|`\[\]]*"\| p\d+$/', $line);
            } elseif (preg_match('/^  p\d+[\[(]/', $line) === 1) {
                self::assertMatchesRegularExpression('/^  p\d+(\["[^"`]*"\]|\[\("[^"`]*"\)\])(:::(new|gone))?$/', $line);
            }
        }
        self::assertStringContainsString('event order#quot;#124;#35;#91;x#93;#96;y#96;', $diagram);
    }

    public function test_the_message_path_of_a_wiring_change_draws_only_the_flow_diagram(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');
        $markdown = $this->presentation('build');
        $path = (int) strpos($markdown, "#### The message's path\n");

        $diagrams = $this->mermaidBlocksOf(substr($markdown, $path, (int) strpos($markdown, '<details><summary>Marked wiring', $path) - $path));

        self::assertCount(1, $diagrams);
        self::assertStringStartsWith("flowchart LR\n", $diagrams[0]);
    }

    public function test_the_build_presentation_draws_the_message_path_before_the_changed_wiring_collapsed_under_it(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        $diagrams = $this->mermaidBlocksOf($markdown);
        self::assertCount(2, $diagrams);
        self::assertStringStartsWith("flowchart LR\n", $diagrams[0]);
        self::assertStringStartsWith('flowchart', $diagrams[1]);
        self::assertStringContainsString('audit', $diagrams[1]);
        $path = strpos($markdown, "#### The message's path\n\n");
        $collapsed = strpos($markdown, "<details><summary>Marked wiring and traced spans</summary>\n\n#### Wiring\n");
        $wiringDiagram = strpos($markdown, "```mermaid\nflowchart", (int) $collapsed);
        self::assertIsInt($path);
        self::assertIsInt($collapsed);
        self::assertIsInt($wiringDiagram);
        self::assertLessThan($collapsed, $path);
        self::assertLessThan(strpos($markdown, "</details>\n", $wiringDiagram), $wiringDiagram);
    }

    public function test_the_build_presentation_lists_the_interceptors_of_a_poll_that_wraps_no_step(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');
        $scheduled = new class () {
            #[Scheduled(endpointId: 'scheduled_handler')]
            #[Poller(fixedRateInMilliseconds: 1)]
            public function handle(): void
            {
            }
        };
        $this->recordAs('Polling', 'after', [$scheduled::class], [$scheduled], [SimpleMessageChannelBuilder::createQueueChannel('notifications')], fn (FlowTestSupport $ecotone) => $ecotone->run('scheduled_handler', ExecutionPollingMetadata::createWithDefaults()->withExecutionAmountLimit(1)));

        $markdown = $this->presentation('build');

        self::assertStringContainsString("### `Polling`\n\nBefore: this scenario was not in the catalogue.", $markdown);
        self::assertStringContainsString('poll scheduled_handler', $markdown);
    }

    public function test_the_build_presentation_lists_every_process_in_run_order_with_its_status_and_step_count(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        self::assertMatchesRegularExpression('/^\*\*Flow · process 1 of 2\*\* · \d+ new steps, 1 step gone · \d+ → \d+ steps\n`send command order\.place, class@anonymous::place`\nNew: `route to audit\.target, whenOrderWasPlaced\.target` in place of `route to whenOrderWasPlaced\.target`, and `span route audit audit\.target`\.$/m', $markdown);
        self::assertMatchesRegularExpression('/^\*\*Sub-flow · process 2 of 2\*\* · sent by process 1 · unchanged · (\d+) steps\n`receive notifications, class@anonymous::whenPlaced`\n\n<details>\n<summary>Steps of process 2<\/summary>$/m', $markdown);
    }

    public function test_the_build_presentation_names_every_changed_scenario_with_how_many_of_its_processes_changed_right_under_the_summary(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        self::assertStringStartsWith("## Message flow: what changed\n\n1 of 1 scenario changes its message path.\n\n- `Placing an order`: 1 of 2 processes changed\n\n", $markdown);
    }

    public function test_a_scenario_whose_processes_run_as_before_is_listed_by_what_changed_instead(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');
        $this->replaceExecutionWithTheOneBefore();

        $markdown = $this->presentation('build');

        self::assertStringContainsString("\n- `Placing an order`: wiring and traced spans only\n", $markdown);
    }

    public function test_the_build_presentation_lists_a_process_that_no_longer_runs_with_its_number_before_and_its_tree_removed(): void
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

        self::assertMatchesRegularExpression('/^\*\*Sub-flow · process 2 of 2 before\*\* · no longer runs · (\d+) steps\n`receive notifications, class@anonymous::whenPlaced`\n\n<details open>\n<summary>Steps of process 2 before<\/summary>\n\n```diff\n- 📬 receive notifications$/m', $markdown);
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

    public function test_the_build_presentation_shows_a_changed_step_with_its_hint_in_the_open_tree_of_its_process_and_folds_what_did_not_change_closed(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $markdown = $this->presentation('build');

        self::assertStringContainsString("<details open>\n<summary>Steps of process 1</summary>", $markdown);
        self::assertMatchesRegularExpression('/^\+ ((?:  )*)🔀 route to audit\.target, whenOrderWasPlaced\.target\n\+ \1  ↳ chosen by: name order\.placed$/m', $markdown);
        self::assertMatchesRegularExpression('/^<details>\n<summary>(?!🟢|🔴)[^\n]*<\/summary>$/m', $markdown);
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

    public function test_the_marked_wiring_keeps_each_diagram_within_fifteen_nodes(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->orderingWithManySubscribers());

        $wiringDiagrams = array_slice($this->mermaidBlocksOf($this->presentation('build')), 1);

        self::assertGreaterThan(1, count($wiringDiagrams));
        foreach ($wiringDiagrams as $diagram) {
            self::assertLessThanOrEqual(15, preg_match_all('/^\s+n\d+[\[\(\{>]/m', $diagram));
        }
    }

    public function test_scenarios_with_the_same_change_share_one_section_that_lists_them(): void
    {
        $this->recordAnOrderThatGainsAnAudit('First ordering');
        $this->recordAnOrderThatGainsAnAudit('Second ordering');

        $markdown = $this->presentation('build');

        self::assertStringContainsString("2 of 2 scenarios change their message path.\n\n- `First ordering`: 1 of 2 processes changed\n- `Second ordering`: 1 of 2 processes changed\n\n### Framework: what changes\n", $markdown);
        self::assertStringContainsString("\n### 2 scenarios with the same change\n\nApplies to: `First ordering`, `Second ordering`.\n\n", $markdown);
        self::assertCount(2, $this->mermaidBlocksOf($markdown));
    }

    public function test_snapshots_without_a_difference_are_presented_as_no_change(): void
    {
        $this->record('Placing an order', 'before', $this->ordering());
        $this->record('Placing an order', 'after', $this->ordering());

        self::assertSame("## Message flow: what changed\n\nThe 1 scenario did not change its wiring, what Ecotone registers for it or its traced spans.\n", $this->presentation('build'));
    }

    public function test_compare_without_a_presentation_prints_what_the_pull_request_comment_shows(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $ecotone = $this->application([], []);
        $ecotone->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after']);

        self::assertSame($this->presentation('build'), $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_a_presentation_other_than_build_or_json_is_refused_naming_the_ones_that_exist(): void
    {
        $this->recordAnOrderThatGainsAnAudit('Placing an order');

        $this->expectExceptionMessage('ecotone:build-flow:compare was asked for presentation `before-after`, which it cannot produce. Pass --presentation=build (the default) for what the pull request comment shows: the framework tree, then per scenario the Before/Now summary, what ran process by process, the message\'s path and the marked wiring collapsed; or --presentation=json for the raw comparison.');

        $this->presentation('before-after');
    }

    private function recordAnEventCalled(string $event, string $side, bool $withAudit): void
    {
        $handlers = $withAudit
            ? new class () {
                #[CommandHandler('order.place', endpointId: 'placeOrder')]
                public function place(array $order, EventBus $eventBus): void
                {
                    $eventBus->publishWithRouting($order['event'], []);
                }

                #[EventHandler('order"|#[x]`y`', endpointId: 'audit')]
                public function audit(): void
                {
                }
            }
        : new class () {
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(array $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting($order['event'], []);
            }
        };
        $this->recordAs('Placing an order', $side, [$handlers::class], [$handlers], [SimpleMessageChannelBuilder::createQueueChannel('notifications')], fn (FlowTestSupport $ecotone) => $ecotone->sendCommandWithRouting('order.place', ['event' => $event]));
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
        $this->recordAs($scenario, $side, [$ordering::class], [$ordering], [SimpleMessageChannelBuilder::createQueueChannel('notifications')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('order.place', []);
            $ecotone->run('notifications', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        });
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
            initialTime: new DateTimeImmutable('2026-11-22 10:00:00'),
        );
    }

    private function replaceExecutionWithTheOneBefore(): void
    {
        $read = fn (string $side): array => json_decode((string) file_get_contents($this->location . '/' . $side . '/placing-an-order.json'), true, flags: JSON_THROW_ON_ERROR);
        $after = $read('after');
        $after['execution'] = $read('before')['execution'];
        file_put_contents($this->location . '/after/placing-an-order.json', json_encode($after, JSON_THROW_ON_ERROR));
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
