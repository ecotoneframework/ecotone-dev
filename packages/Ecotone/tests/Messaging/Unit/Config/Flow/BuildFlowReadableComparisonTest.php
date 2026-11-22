<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use DateTimeImmutable;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionBackfill;
use Ecotone\Api\Projecting\ProjectionRebuild;
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
final class BuildFlowReadableComparisonTest extends TestCase
{
    private const BEFORE = 'Before: nothing was sent after `process partition batch orders`.';
    private const NOW = 'Now: after each `process partition batch orders`, the Enterprise part completion, `GatheringPartCompletion`, sends a part report to `ecotone.projection.rebuild.completion:orders`, which goes on to `rebuild`; it is received from `rebuild`; it is routed to the gathered handler `ProjectionRunCompletion::complete` (read from the wiring, not recorded).';

    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-build-flow-readable-' . bin2hex(random_bytes(6));
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

    public function test_changes_that_differ_only_by_one_name_are_one_group_naming_what_varies(): void
    {
        $this->recordARunThatGainsItsCompletion('backfill');
        $this->recordARunThatGainsItsCompletion('rebuild');

        $comparison = $this->comparison('json');

        self::assertCount(1, $comparison['groups']);
        self::assertSame(['projection backfill run', 'projection rebuild run'], $comparison['groups'][0]['scenarios']);
        self::assertSame(['backfill', 'rebuild'], $comparison['groups'][0]['varyingNames']);
    }

    public function test_changes_that_each_hold_both_names_and_differ_by_exchanging_them_are_one_group(): void
    {
        $this->recordARunThatGainsItsCompletion('backfill');
        $this->recordARunThatGainsItsCompletion('rebuild');
        foreach (['before' => 'Acme\\SlowPlan', 'after' => 'Acme\\FastPlan'] as $side => $class) {
            foreach (['backfill', 'rebuild'] as $operation) {
                $file = $this->location . '/' . $side . "/projection-{$operation}-run.json";
                $stored = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                $stored['build']['ledger'][] = ['module' => 'Acme\\PlanningModule', 'kind' => 'service', 'name' => 'Acme\\BackfillPlan', 'class' => $class];
                $stored['build']['ledger'][] = ['module' => 'Acme\\PlanningModule', 'kind' => 'service', 'name' => 'Acme\\RebuildPlan', 'class' => $class];
                file_put_contents($file, json_encode($stored, JSON_THROW_ON_ERROR));
            }
        }

        $comparison = $this->comparison('json');

        self::assertCount(1, $comparison['groups']);
        self::assertSame(['backfill', 'rebuild'], $comparison['groups'][0]['varyingNames']);
    }

    public function test_changes_that_differ_by_more_than_a_name_stay_separate_groups(): void
    {
        $this->recordARunThatGainsItsCompletion('backfill');
        $this->recordARunThatGainsItsCompletion('rebuild', withoutTheNewSteps: true);

        self::assertCount(2, $this->comparison('json')['groups']);
    }

    public function test_added_nodes_that_connect_to_nothing_on_the_path_are_listed_instead_of_drawn(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $group = $this->comparison('json')['groups'][0];
        $drawn = array_merge(...array_column($group['wiringParts'], 'nodes'));

        self::assertContains('endpoint:orders.rebuild', $drawn);
        self::assertContains('channel:rebuild', $drawn);
        self::assertNotContains('endpoint:ecotone.gathering.cleanup', $drawn);
        self::assertContains('endpoint:ecotone.gathering.cleanup', $group['registeredOffThePath']['added']);
        self::assertSame([], $group['registeredOffThePath']['removed']);
        self::assertSame([], array_values(array_filter($drawn, fn (string $id): bool => str_contains($id, 'gathering'))));
    }

    public function test_an_added_receive_without_a_span_below_it_continues_with_the_handler_the_wiring_routes_it_to(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $trace = $this->comparison('json')['groups'][0]['trace'];

        self::assertSame(
            [
                ['change' => 'added', 'step' => ['depth' => 3, 'name' => 'send rebuild', 'kind' => 'Producer', 'status' => 'Ok', 'partReport' => ['sender' => 'Ecotone\\Messaging\\Handler\\Gathering\\GatheringPartCompletion', 'chosenFor' => 'Ecotone\\Messaging\\Handler\\Gathering\\PartCompletion', 'channel' => 'ecotone.projection.rebuild.completion:orders', 'gatherer' => 'Ecotone\\Projecting\\ProjectionRunCompletion::complete']]],
                ['change' => 'added', 'step' => ['depth' => 4, 'name' => 'receive rebuild', 'kind' => 'Consumer', 'status' => 'Ok']],
                ['change' => 'added', 'step' => ['depth' => 5, 'name' => 'process ProjectionRunCompletion::complete', 'kind' => 'Wiring', 'status' => 'Ok', 'derivedFromWiring' => true]],
            ],
            array_slice($trace, -3),
        );
    }

    public function test_few_unchanged_steps_are_all_kept_as_context(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $trace = $this->comparison('json')['groups'][0]['trace'];

        self::assertSame(['unchanged', 'unchanged', 'unchanged', 'unchanged', 'unchanged', 'unchanged', 'added', 'added', 'added'], array_column($trace, 'change'));
    }

    public function test_the_change_is_summarised_as_what_happened_before_and_what_happens_now(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        self::assertSame(['before' => self::BEFORE, 'now' => self::NOW], $this->comparison('json')['groups'][0]['summary']);
    }

    public function test_every_presentation_opens_with_the_summary_names_the_scenarios_it_applies_to_and_leaves_what_is_off_the_path_to_the_build(): void
    {
        $this->recordARunThatGainsItsCompletion('backfill');
        $this->recordARunThatGainsItsCompletion('rebuild');

        foreach (['before-after', 'marked', 'path'] as $presentation) {
            $markdown = $this->comparison($presentation);

            self::assertStringStartsWith(
                "## Message flow: what changed\n\n2 of 2 scenarios change their message path.\n\n### 2 scenarios with the same change\n\n"
                . "Applies to: `projection backfill run`, `projection rebuild run`. Shown for `backfill`; the same holds with `rebuild` in its place.\n\n"
                . str_replace('rebuild', 'backfill', self::BEFORE) . "\n\n" . str_replace('rebuild', 'backfill', self::NOW) . "\n\n",
                $markdown,
                $presentation,
            );
            self::assertStringNotContainsString('not on this path', $markdown, $presentation);
            self::assertStringNotContainsString('Build: what', $markdown, $presentation);
            self::assertStringContainsString($presentation === 'path' ? "\nGreen is a hand-over that happens only now" : "\n_Legend: ", $markdown, $presentation);
            self::assertStringNotContainsString('class@anonymous', $markdown, $presentation);
            self::assertStringNotContainsString('Bridge::handle', $markdown, $presentation);
            self::assertStringNotContainsString('ecotone.channel.ecotone:', $markdown, $presentation);
        }
    }

    public function test_diagrams_use_words_a_reviewer_knows_and_explain_their_marks(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $marked = $this->comparison('marked');

        self::assertStringContainsString('"Internal Handler<br/>consumer of rebuild"', $marked);
        self::assertStringContainsString('"Internal Handler<br/>ProjectionRunCompletion::complete"', $marked);
        self::assertStringContainsString('("Channel<br/>ecotone.projection.rebuild.completion:orders (entry point: part reports are sent here by GatheringPartCompletion)")', $marked);
        self::assertStringContainsString('-->|"then sends to"|', $marked);
        self::assertStringContainsString('-->|"routed to"|', $marked);
        self::assertStringNotContainsString('|"output"|', $marked);
        self::assertStringNotContainsString('|"routes"|', $marked);
        self::assertStringContainsString('_Legend: green = added; dotted outline = registered by Ecotone, solid outline = declared by the application; `then sends to` = the handler\'s result goes to that channel, `routed to` = chosen at runtime by a bus or a routing slip, an unlabelled arrow out of a channel = consumed by._', $marked);
        self::assertStringNotContainsString('red dashed', $marked);
        self::assertStringNotContainsString('yellow', $marked);
        self::assertStringContainsString("+      send rebuild (part report by GatheringPartCompletion to ecotone.projection.rebuild.completion:orders)\n+        receive rebuild\n+          process ProjectionRunCompletion::complete (from the wiring: no span is recorded)\n", $marked);
        self::assertStringNotContainsString('steps unchanged', $marked);
    }

    public function test_before_after_pair_marks_the_new_steps_in_its_after_list(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $pair = $this->comparison('before-after');

        self::assertStringContainsString("        process whenPlaced of projection orders\n```\n\nAfter:", $pair);
        self::assertStringContainsString("      send rebuild (part report by GatheringPartCompletion to ecotone.projection.rebuild.completion:orders)   ← new\n        receive rebuild   ← new\n          process ProjectionRunCompletion::complete (from the wiring: no span is recorded)   ← new\n```\n", $pair);
        self::assertStringContainsString('_Legend: dotted outline = registered by Ecotone, solid outline = declared by the application; ', $pair);
    }

    public function test_message_path_draws_sender_channel_and_receiving_handler_as_separate_nodes(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $path = $this->comparison('path');

        foreach (['consumer of rebuild', 'partition batch orders', 'gatherer (Enterprise only)', 'ProjectionRunCompletion::complete'] as $box) {
            self::assertMatchesRegularExpression('/^  p\d+\["' . preg_quote($box, '/') . '"\](:::new)?$/m', $path);
        }
        foreach (['channel rebuild', 'channel ecotone.projection.rebuild.completion:orders', 'channel ecotone.projection.rebuild.completion:orders.execute'] as $channel) {
            self::assertMatchesRegularExpression('/^  p\d+\[\("' . preg_quote($channel, '/') . '"\)\](:::new)?$/m', $path);
        }
        self::assertMatchesRegularExpression('/^  p\d+\["GatheringPartCompletion"\]:::new$/m', $path);
        foreach (['-.->|"+ ends, its part completion runs"|', '-.->|"+ sends part report"|', '-->|"+ asynchronous routing sends"|', '-.->|"+ routed to"|', '-.->|"+ delivers"|', '-.->|"+ invokes"|'] as $edge) {
            self::assertMatchesRegularExpression('/^  p\d+ ' . preg_quote($edge, '/') . ' p\d+$/m', $path);
        }
        self::assertStringNotContainsString('no span)', $path);
        self::assertDoesNotMatchRegularExpression('/^  (p\d+) -\.?->\|"[^"]*"\| \1$/m', $path);
        self::assertStringNotContainsString('sequenceDiagram', $path);
        self::assertStringNotContainsString('nodes added', $path);
        self::assertStringNotContainsString('- Traced spans:', $path);
        self::assertStringContainsString("Green is a hand-over that happens only now, red one that happened only before, plain one that happens both before and now, and a dashed arrow is taken from the wiring because no span is recorded.\n", $path);
    }

    public function test_the_sentence_the_marked_wiring_and_the_traced_steps_name_the_same_sender(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $markdown = $this->comparison('build');

        self::assertSame(1, preg_match('/^Now: after each .*the Enterprise part completion, `GatheringPartCompletion`, sends a part report to `ecotone\.projection\.rebuild\.completion:orders`/m', $markdown));
        self::assertMatchesRegularExpression('/^  p\d+\["GatheringPartCompletion"\]:::new$/m', $markdown);
        self::assertStringContainsString('ecotone.projection.rebuild.completion:orders (entry point: part reports are sent here by GatheringPartCompletion)', $markdown);
        self::assertStringContainsString('send rebuild (part report by GatheringPartCompletion to ecotone.projection.rebuild.completion:orders)', $markdown);
        self::assertStringNotContainsString('the consumer of `rebuild` sends', $markdown);
    }

    public function test_a_change_of_the_build_alone_is_summarised_as_such_and_draws_nothing(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild', withoutTheNewSteps: true);
        copy($this->location . '/after/projection-rebuild-run.json', $this->location . '/before/projection-rebuild-run.json');
        $stored = json_decode((string) file_get_contents($this->location . '/after/projection-rebuild-run.json'), true, flags: JSON_THROW_ON_ERROR);
        $stored['build']['ledger'][] = ['module' => 'Acme\\PlanningModule', 'kind' => 'service', 'name' => 'Acme\\Plan', 'class' => 'Acme\\Plan'];
        $stored['build']['ledger'][] = ['module' => 'Acme\\PlanningModule', 'kind' => 'service', 'name' => 'Acme\\Planner', 'class' => 'Acme\\Planner'];
        file_put_contents($this->location . '/after/projection-rebuild-run.json', json_encode($stored, JSON_THROW_ON_ERROR));

        $markdown = $this->comparison('build');

        self::assertSame(
            "## Message flow: what changed\n\nNo scenario of 1 changes its message path. 1 changes only its build.\n\n"
            . "### Build: what changes\n\n```text\nMessagingSystem\n└ PlanningModule\n  └ + 2 services\n```\n\n"
            . "<details><summary>Counted registrations</summary>\n\n- PlanningModule + service: `Plan`, `Planner`\n\n</details>\n",
            $markdown,
        );
        self::assertStringNotContainsString('mermaid', $markdown);
        self::assertStringNotContainsString('Legend', $markdown);
    }

    private function recordARunThatGainsItsCompletion(string $operation, bool $withoutTheNewSteps = false): void
    {
        $projection = $operation === 'rebuild'
            ? new #[Projection('orders'), FromStream('orders_stream'), ProjectionRebuild(asyncChannelName: 'rebuild')] class () {
                #[EventHandler('order.placed')]
                public function whenPlaced(array $event): void
                {
                }
            }
        : new #[Projection('orders'), FromStream('orders_stream'), ProjectionBackfill(asyncChannelName: 'backfill')] class () {
            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
            }
        };
        $ecotone = $this->application([$projection::class], [$projection], [SimpleMessageChannelBuilder::createQueueChannel($operation)]);
        $ecotone->withEventStream('orders_stream', [Event::createWithType('order.placed', ['orderId' => '1'])]);
        $ecotone->runConsoleCommand('ecotone:projection:' . $operation, ['name' => 'orders']);
        $ecotone->run($operation, ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->runConsoleCommand('ecotone:build-flow:record', ['scenario' => "projection {$operation} run", 'location' => $this->location . '/after']);

        $file = "projection-{$operation}-run.json";
        $now = json_decode((string) file_get_contents($this->location . '/after/' . $file), true, flags: JSON_THROW_ON_ERROR);
        $addedByTheChange = fn (string $id): bool => str_contains($id, 'gathering') || str_contains($id, '.completion:') || $id === 'endpoint:orders.' . $operation;
        $before = $now;
        $before['wiring']['nodes'] = array_values(array_filter($now['wiring']['nodes'], fn (array $node): bool => ! $addedByTheChange($node['id'])));
        $before['wiring']['edges'] = array_values(array_filter($now['wiring']['edges'], fn (array $edge): bool => ! $addedByTheChange($edge['from']) && ! $addedByTheChange($edge['to'])));
        $before['trace'] = array_slice($now['trace'], 0, -2);
        if ($withoutTheNewSteps) {
            $now['trace'] = $before['trace'];
            file_put_contents($this->location . '/after/' . $file, json_encode($now, JSON_THROW_ON_ERROR));
        }
        if (! is_dir($this->location . '/before')) {
            mkdir($this->location . '/before');
        }
        file_put_contents($this->location . '/before/' . $file, json_encode($before, JSON_THROW_ON_ERROR));
    }

    /**
     * @return ($presentation is 'json' ? array<string, mixed> : string)
     */
    private function comparison(string $presentation): array|string
    {
        $ecotone = $this->application([], [], []);
        $ecotone->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after', 'presentation' => $presentation]);
        $written = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();

        return $presentation === 'json' ? json_decode($written, true, flags: JSON_THROW_ON_ERROR) : $written;
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     * @param list<object> $extensionObjects
     */
    private function application(array $classes, array $services, array $extensionObjects): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [...$classes, BuildFlowConsoleCommands::class],
            [...$services, new BuildFlowConsoleCommands()],
            [...$extensionObjects, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-11-22 10:00:00'),
        );
    }
}
