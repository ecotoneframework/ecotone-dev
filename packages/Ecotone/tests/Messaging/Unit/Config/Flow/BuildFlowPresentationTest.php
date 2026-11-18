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

        self::assertStringStartsWith("## Message flow: what changed\n\n1 of 1 scenarios changes its message path.\n\n### Build: what changes\n\n```text\nMessagingSystem\n", $markdown);
        self::assertStringContainsString("\n### `Placing an order`\n\nBefore: `send event order.placed` was followed directly by `route whenOrderWasPlaced`.\n\nNow: after `send event order.placed`, it is routed to `audit.target`; `event handler audit` handles it.\n\n#### Wiring\n\nBefore:\n\n```mermaid\nflowchart LR\n", $markdown);
        [$before, $after] = $this->mermaidBlocksOf($markdown);
        self::assertStringNotContainsString('audit', $before);
        self::assertStringContainsString('Event Handler<br/>audit', $after);
        self::assertStringContainsString('Channel<br/>audit.target', $after);
        self::assertStringNotContainsString('classDef added', $after);
        self::assertStringContainsString(
            "#### Traced steps\n\nBefore:\n\n```text\nsend command order.place\n  process command handler place\n    send event order.placed\n      route whenOrderWasPlaced → whenOrderWasPlaced.target\n  send notifications\n    receive notifications\n      process event handler whenPlaced\n```\n\n"
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
            "#### Traced steps\n\n```diff\n send command order.place\n   process command handler place\n     send event order.placed\n+      route audit → audit.target\n+        process event handler audit\n       route whenOrderWasPlaced → whenOrderWasPlaced.target\n   send notifications\n     receive notifications\n       process event handler whenPlaced\n```\n",
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
        [, $before, $after] = preg_split('/^    rect rgb\(.*\)$/m', $diagrams[0]);
        self::assertStringContainsString('Before', $before);
        self::assertStringNotContainsString('audit', $before);
        self::assertStringContainsString('After', $after);
        self::assertMatchesRegularExpression('/^    p\d+->>p\d+: \+ handles$/m', $after);
        self::assertMatchesRegularExpression('/^    participant p\d+ as event handler audit$/m', $diagrams[0]);
        self::assertStringContainsString("Before: `send event order.placed` was followed directly by `route whenOrderWasPlaced`.\n\nNow: after `send event order.placed`, it is routed to `audit.target`; `event handler audit` handles it.\n\n#### The message's path\n", $markdown);
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
            self::assertSame("## Message flow: what changed\n\nNone of 1 scenarios changed its wiring, its build or its traced steps.\n", $this->presentation($presentation));
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ecotone:build-flow:compare was asked for presentation `D`, which it cannot produce. Pass --presentation=before-after (the default) for a before/after pair of diagrams and step lists, --presentation=marked for one diagram with the changes marked and one step list, --presentation=path for the message\'s path as a sequence diagram with what changed in sentences, --presentation=build for the build, the message\'s path and the marked wiring collapsed, or --presentation=json for the raw comparison.');

        $this->presentation('D');
    }

    private function recordAnOrderThatGainsAnAudit(string $scenario): void
    {
        $this->record($scenario, 'before', $this->ordering());
        $this->record($scenario, 'after', $this->orderingWithAudit());
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
            [...$services, new BuildFlowConsoleCommands(), InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withExecutionRecording()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
