<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

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
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Test\Flow\Snapshot\InternalFlowConsoleCommands;
use Ecotone\Test\LicenceTesting;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * licence Apache-2.0
 * @internal
 */
final class InternalFlowReadableComparisonTest extends TestCase
{
    private const BEFORE = 'Before: the traced flow ended with `process whenPlaced of projection orders`.';
    private const NOW = 'Now: after `process whenPlaced of projection orders`, the consumer of `rebuild` sends a message to `rebuild`; it is received from `rebuild`; it is routed to `ProjectionRunCompletion::complete` (from the wiring: no span is recorded for that handler).';

    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-internal-flow-readable-' . bin2hex(random_bytes(6));
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
                ['change' => 'added', 'step' => ['depth' => 3, 'name' => 'send rebuild', 'kind' => 'Producer', 'status' => 'Ok']],
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

    public function test_every_presentation_opens_with_the_summary_names_the_scenarios_it_applies_to_and_lists_what_is_off_the_path(): void
    {
        $this->recordARunThatGainsItsCompletion('backfill');
        $this->recordARunThatGainsItsCompletion('rebuild');

        foreach (['A', 'B', 'C'] as $presentation) {
            $markdown = $this->comparison($presentation);

            self::assertStringStartsWith(
                "## Message flow: what changed\n\n2 of 2 scenarios changed.\n\n### 2 scenarios with the same change\n\n"
                . "Applies to: `projection backfill run`, `projection rebuild run`. Shown for `backfill`; the same holds with `rebuild` in its place.\n\n"
                . str_replace('rebuild', 'backfill', self::BEFORE) . "\n\n" . str_replace('rebuild', 'backfill', self::NOW) . "\n\n",
                $markdown,
                $presentation,
            );
            self::assertStringContainsString("\nAlso registered now, not on this path: `closed-group cleanup`, `console command ecotone:gathering:discard`, `console command ecotone:gathering:list`, `console command ecotone:gathering:release`.\n", $markdown, $presentation);
            self::assertStringContainsString("\n_Legend: ", $markdown, $presentation);
            self::assertStringNotContainsString('class@anonymous', $markdown, $presentation);
            self::assertStringNotContainsString('Bridge::handle', $markdown, $presentation);
            self::assertStringNotContainsString('ecotone.channel.ecotone:', $markdown, $presentation);
        }
    }

    public function test_diagrams_use_words_a_reviewer_knows_and_explain_their_marks(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $marked = $this->comparison('B');

        self::assertStringContainsString('"Internal Handler<br/>consumer of rebuild"', $marked);
        self::assertStringContainsString('"Internal Handler<br/>ProjectionRunCompletion::complete"', $marked);
        self::assertStringContainsString('("Channel<br/>ecotone.projection.rebuild.completion:orders (entry point: sent to from code)")', $marked);
        self::assertStringContainsString('-->|"then sends to"|', $marked);
        self::assertStringContainsString('-->|"routed to"|', $marked);
        self::assertStringNotContainsString('|"output"|', $marked);
        self::assertStringNotContainsString('|"routes"|', $marked);
        self::assertStringContainsString('_Legend: green = added, red dashed = removed, yellow = changed; dotted outline = registered by Ecotone, solid outline = declared by the application; `then sends to` = the handler\'s result goes to that channel, `routed to` = chosen at runtime by a bus or a routing slip, an unlabelled arrow out of a channel = consumed by._', $marked);
        self::assertStringContainsString("+      send rebuild\n+        receive rebuild\n+          process ProjectionRunCompletion::complete (from the wiring: no span is recorded)\n", $marked);
        self::assertStringNotContainsString('steps unchanged', $marked);
    }

    public function test_before_after_pair_marks_the_new_steps_in_its_after_list(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $pair = $this->comparison('A');

        self::assertStringContainsString("        process whenPlaced of projection orders\n```\n\nAfter:", $pair);
        self::assertStringContainsString("      send rebuild   ← new\n        receive rebuild   ← new\n          process ProjectionRunCompletion::complete (from the wiring: no span is recorded)   ← new\n```\n", $pair);
        self::assertStringContainsString('_Legend: dotted outline = registered by Ecotone, solid outline = declared by the application; ', $pair);
    }

    public function test_message_path_draws_sender_channel_and_receiving_handler_as_separate_participants(): void
    {
        $this->recordARunThatGainsItsCompletion('rebuild');

        $path = $this->comparison('C');

        self::assertStringContainsString('    participant p4 as consumer of rebuild', $path);
        self::assertStringContainsString('    participant p3 as channel rebuild', $path);
        self::assertStringContainsString('    participant p7 as ProjectionRunCompletion::complete', $path);
        self::assertMatchesRegularExpression('/^    Note over p0,p7: Before$/m', $path);
        self::assertMatchesRegularExpression('/^    Note over p0,p7: After$/m', $path);
        self::assertStringContainsString("    p4->>p3: + sends\n    p3->>p4: + delivers\n    p4-->>p7: + routed to (wiring, no span)\n", $path);
        self::assertDoesNotMatchRegularExpression('/^    (p\d+)--?>>\1:/m', $path);
        self::assertStringNotContainsString('nodes added', $path);
        self::assertStringNotContainsString('- Traced steps:', $path);
        self::assertStringContainsString('_Legend: solid arrow = a traced step, dashed arrow = taken from the wiring because no span is recorded; `+` = new, `-` = gone._', $path);
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
        $ecotone->run($operation, ExecutionPollingMetadata::createWithTestingSetup());
        $ecotone->runConsoleCommand('ecotone:internal-flow:record', ['scenario' => "projection {$operation} run", 'location' => $this->location . '/after']);

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
        $ecotone->runConsoleCommand('ecotone:internal-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after', 'presentation' => $presentation]);
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
            [...$classes, InternalFlowConsoleCommands::class],
            [...$services, new InternalFlowConsoleCommands(), InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [...$extensionObjects, TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
