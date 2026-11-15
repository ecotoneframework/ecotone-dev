<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Splitter;
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
final class BuildFlowLedgerComparisonTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-build-flow-build-comparison-' . bin2hex(random_bytes(6));
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

    public function test_a_plain_handler_that_gains_a_splitter_is_exactly_one_changed_entry_with_a_changed_chain(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledByASplitter());

        $build = $this->comparison()['groups'][0]['build'];

        self::assertCount(1, $build);
        self::assertSame(['channel:order.lines', 'handler:handleLines'], array_keys($this->entriesOf($build)));
        $channel = $build[0]['entries'][0];
        self::assertSame(['module'], array_keys(array_diff_assoc($channel['after'], $channel['before'])));
        $entry = $build[0]['entries'][1];
        self::assertSame(['changed', 'handler', 'handleLines'], [$entry['change'], $entry['kind'], $entry['name']]);
        self::assertSame($entry['after']['module'], $build[0]['module']);
        self::assertSame('service activator', $entry['before']['builder']);
        self::assertSame('splitter', $entry['after']['builder']);
        self::assertContains(['change' => 'unchanged', 'layer' => 'event driven consumer of order.lines'], $entry['chain']);
        self::assertContains(['change' => 'removed', 'layer' => 'handler class@anonymous#1::handle()'], $entry['chain']);
        self::assertContains(['change' => 'added', 'layer' => 'splitter class@anonymous#1::handle()'], $entry['chain']);
        self::assertContains(['change' => 'removed', 'layer' => 'reply channel of the message'], $entry['chain']);
    }

    public function test_an_added_registration_is_listed_under_the_module_that_made_it_with_its_whole_chain(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledWithAnAudit());

        $build = $this->comparison()['groups'][0]['build'];
        $entries = $this->entriesOf($build);

        self::assertSame('added', $entries['handler:audit']['change']);
        self::assertArrayNotHasKey('before', $entries['handler:audit']);
        self::assertStringEndsWith('Module', $entries['handler:audit']['module']);
        self::assertSame(['added'], array_values(array_unique(array_column($entries['handler:audit']['chain'], 'change'))));
        self::assertSame('event driven consumer of audit.target', $entries['handler:audit']['chain'][0]['layer']);
        self::assertSame('added', $entries['channel:audit.target']['change']);
        self::assertArrayNotHasKey('chain', $entries['channel:audit.target']);
        self::assertArrayNotHasKey('handler:handleLines', $entries);
        $modules = array_column($build, 'module');
        $sorted = $modules;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $modules);
    }

    public function test_a_removed_registration_is_listed_with_the_chain_it_had(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledWithAnAudit());
        $this->record('Handling lines', 'after', $this->linesHandledByAPlainHandler());

        $entries = $this->entriesOf($this->comparison()['groups'][0]['build']);

        self::assertSame('removed', $entries['handler:audit']['change']);
        self::assertArrayNotHasKey('after', $entries['handler:audit']);
        self::assertSame(['removed'], array_values(array_unique(array_column($entries['handler:audit']['chain'], 'change'))));
    }

    public function test_a_handler_whose_ledger_entry_stays_but_whose_chain_changes_is_listed_with_the_changed_layers(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledByAPlainHandlerTakingAPayload());

        $entries = $this->entriesOf($this->comparison()['groups'][0]['build']);

        self::assertSame(['handler:handleLines'], array_keys($entries));
        self::assertSame('changed', $entries['handler:handleLines']['change']);
        self::assertSame($entries['handler:handleLines']['before'], $entries['handler:handleLines']['after']);
        self::assertContains(['change' => 'removed', 'layer' => 'handler class@anonymous#1::handle()'], $entries['handler:handleLines']['chain']);
        self::assertContains(['change' => 'added', 'layer' => 'handler class@anonymous#1::handle(array)'], $entries['handler:handleLines']['chain']);
    }

    public function test_scenarios_with_the_same_build_change_stay_one_group(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledByASplitter());
        $this->record('Handling lines again', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines again', 'after', $this->linesHandledByASplitter());
        $this->record('Handling lines as before', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines as before', 'after', $this->linesHandledByAPlainHandler());

        $comparison = $this->comparison();

        self::assertSame([['Handling lines', 'Handling lines again']], array_column($comparison['groups'], 'scenarios'));
        self::assertSame([], $comparison['everyScenario']['build']);
        self::assertNotSame([], $comparison['groups'][0]['build']);
    }

    public function test_a_build_change_of_what_every_application_gets_is_listed_once_and_left_out_of_the_groups(): void
    {
        $this->recordTwoScenariosSharingAChangedReportsService();

        $comparison = $this->comparison();

        self::assertSame(['service:Acme\Reports'], array_keys($this->entriesOf($comparison['everyScenario']['build'])));
        self::assertSame('Acme\ReportingModule', $comparison['everyScenario']['build'][0]['module']);
        self::assertSame('Acme\FasterReports', $comparison['everyScenario']['build'][0]['entries'][0]['after']['class']);
        self::assertCount(2, $comparison['groups']);
        foreach ($comparison['groups'] as $group) {
            self::assertArrayNotHasKey('service:Acme\Reports', $this->entriesOf($group['build']));
        }
    }

    public function test_a_scenario_changed_only_by_what_every_application_gets_is_not_a_group_of_its_own(): void
    {
        $this->recordTwoScenariosSharingAChangedReportsService();
        copy($this->location . '/before/handling-lines-again.json', $this->location . '/after/handling-lines-again.json');
        $again = json_decode((string) file_get_contents($this->location . '/after/handling-lines-again.json'), true, flags: JSON_THROW_ON_ERROR);
        $again['build']['ledger'][array_key_last($again['build']['ledger'])]['class'] = 'Acme\FasterReports';
        file_put_contents($this->location . '/after/handling-lines-again.json', json_encode($again, JSON_THROW_ON_ERROR));

        $comparison = $this->comparison();

        self::assertSame([['Handling lines']], array_column($comparison['groups'], 'scenarios'));
        self::assertSame(['service:Acme\Reports'], array_keys($this->entriesOf($comparison['everyScenario']['build'])));
    }

    public function test_a_change_of_the_build_alone_makes_a_scenario_changed(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledByAPlainHandler());
        $stored = json_decode((string) file_get_contents($this->location . '/after/handling-lines.json'), true, flags: JSON_THROW_ON_ERROR);
        $stored['build']['ledger'][] = ['module' => 'Acme\ReportingModule', 'kind' => 'service', 'name' => 'Acme\Reports', 'class' => 'Acme\Reports'];
        file_put_contents($this->location . '/after/handling-lines.json', json_encode($stored, JSON_THROW_ON_ERROR));

        $comparison = $this->comparison();

        self::assertSame([['Handling lines']], array_column($comparison['groups'], 'scenarios'));
        self::assertSame([['module' => 'Acme\ReportingModule', 'entries' => [['change' => 'added', 'kind' => 'service', 'name' => 'Acme\Reports', 'after' => ['module' => 'Acme\ReportingModule', 'kind' => 'service', 'name' => 'Acme\Reports', 'class' => 'Acme\Reports']]]]], $comparison['groups'][0]['build']);
    }

    public function test_every_presentation_lists_what_modules_register_now_between_the_sentences_and_its_diagrams(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledWithAnAudit());

        foreach (['A' => '#### Wiring', 'B' => '#### Wiring', 'C' => "#### The message's path", 'BUILD' => "#### The message's path"] as $presentation => $nextHeading) {
            $markdown = $this->presentation($presentation);

            self::assertLessThan(strpos($markdown, '#### Build: what modules register now'), strpos($markdown, 'Now: '), $presentation);
            self::assertLessThan(strpos($markdown, $nextHeading), strpos($markdown, '#### Build: what modules register now'), $presentation);
            self::assertStringContainsString("\n  + handler audit: service activator on audit.target, invokes class@anonymous#1::audit\n      event driven consumer of audit.target\n", $markdown, $presentation);
            self::assertStringContainsString("\n      handler class@anonymous#1::audit()\n      then: routing slip, else part report (GatheringPartCompletion with an Enterprise licence, NullPartCompletion in open core), else reply channel\n", $markdown, $presentation);
            self::assertStringContainsString("\n  + channel audit.target (subscribable)\n", $markdown, $presentation);
            self::assertMatchesRegularExpression('/^```text\n[A-Z][A-Za-z]+Module\n  [+~-] /m', $markdown, $presentation);
            self::assertStringNotContainsString('Ecotone\\', substr($markdown, strpos($markdown, '#### Build'), strpos($markdown, $nextHeading) - strpos($markdown, '#### Build')), $presentation);
        }
    }

    public function test_a_changed_chain_is_presented_as_the_layers_removed_and_added_between_the_ones_that_stay(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledByASplitter());

        $markdown = $this->presentation('BUILD');

        self::assertStringContainsString(
            "  ~ handler handleLines: splitter on order.lines, invokes class@anonymous#1::handle\n"
            . "      was: handler handleLines: service activator on order.lines, invokes class@anonymous#1::handle\n"
            . "      was registered by ServiceActivatorModule\n"
            . "        event driven consumer of order.lines\n",
            $markdown,
        );
        self::assertStringContainsString("\n      - handler class@anonymous#1::handle()\n", $markdown);
        self::assertStringContainsString("\n      + splitter class@anonymous#1::handle()\n", $markdown);
        self::assertStringContainsString("  ~ channel order.lines (subscribable)\n      was registered by ServiceActivatorModule\n", $markdown);
    }

    public function test_a_removed_registration_is_presented_with_a_minus(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledWithAnAudit());
        $this->record('Handling lines', 'after', $this->linesHandledByAPlainHandler());

        self::assertStringContainsString("\n  - handler audit: service activator on audit.target, invokes class@anonymous#1::audit\n", $this->presentation('B'));
    }

    public function test_the_build_presentation_puts_the_build_then_the_message_path_then_the_marked_wiring_collapsed(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledWithAnAudit());

        $markdown = $this->presentation('BUILD');

        $positions = array_map(fn (string $part): int|false => strpos($markdown, $part), ['Before', 'Now: ', '#### Build: what modules register now', "#### The message's path", '<details><summary>Marked wiring and traced steps</summary>', '#### Wiring', 'classDef added', '</details>']);
        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
    }

    public function test_a_service_chosen_by_the_licence_is_presented_with_both_choices(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledByAPlainHandler());
        $stored = json_decode((string) file_get_contents($this->location . '/after/handling-lines.json'), true, flags: JSON_THROW_ON_ERROR);
        $stored['build']['ledger'][] = ['module' => 'Acme\Reporting\ReportingModule', 'kind' => 'service', 'name' => 'Acme\Reporting\Reports', 'class' => 'Acme\Reporting\Reports', 'enterprise' => 'Acme\Reporting\GatheredReports', 'openCore' => 'Acme\Reporting\PlainReports'];
        file_put_contents($this->location . '/after/handling-lines.json', json_encode($stored, JSON_THROW_ON_ERROR));

        self::assertStringContainsString("```text\nReportingModule\n  + service Reports: Enterprise -> GatheredReports, open core -> PlainReports\n```", $this->presentation('C'));
    }

    public function test_a_build_change_of_what_every_application_gets_is_presented_once_before_the_scenarios(): void
    {
        $this->recordTwoScenariosSharingAChangedReportsService();

        $markdown = $this->presentation('BUILD');

        self::assertSame(1, substr_count($markdown, '~ service Reports'));
        self::assertStringContainsString("### Every scenario\n\n#### Build: what modules register now\n\n```text\nReportingModule\n  ~ service Reports: FasterReports\n      was: service Reports\n```\n", $markdown);
        self::assertLessThan(strpos($markdown, '### `Handling lines`'), strpos($markdown, '### Every scenario'));
        self::assertStringContainsString('2 of 2 scenarios changed beyond what every scenario shares.', $markdown);
    }

    public function test_a_scenario_whose_build_is_unchanged_says_so(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledByAPlainHandler());
        $stored = json_decode((string) file_get_contents($this->location . '/after/handling-lines.json'), true, flags: JSON_THROW_ON_ERROR);
        $stored['trace'][] = ['depth' => 0, 'name' => 'send command', 'destination' => 'order.audit', 'kind' => 'Server', 'status' => 'Ok'];
        file_put_contents($this->location . '/after/handling-lines.json', json_encode($stored, JSON_THROW_ON_ERROR));

        self::assertStringContainsString("#### Build: what modules register now\n\nUnchanged.\n", $this->presentation('BUILD'));
    }

    private function recordTwoScenariosSharingAChangedReportsService(): void
    {
        foreach (['Handling lines' => $this->linesHandledByASplitter(), 'Handling lines again' => $this->linesHandledWithAnAudit()] as $scenario => $after) {
            $this->record($scenario, 'before', $this->linesHandledByAPlainHandler());
            $this->record($scenario, 'after', $after);
        }
        foreach (['before' => 'Acme\Reports', 'after' => 'Acme\FasterReports'] as $side => $class) {
            $service = ['module' => 'Acme\ReportingModule', 'kind' => 'service', 'name' => 'Acme\Reports', 'class' => $class];
            foreach (['handling-lines', 'handling-lines-again'] as $fileName) {
                $file = $this->location . '/' . $side . '/' . $fileName . '.json';
                $stored = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                $stored['build']['ledger'][] = $service;
                file_put_contents($file, json_encode($stored, JSON_THROW_ON_ERROR));
            }
            file_put_contents($this->location . '/' . $side . '/shared.json', json_encode(['formatVersion' => 2, 'wiring' => ['nodes' => [], 'edges' => []], 'build' => ['ledger' => [$service], 'chains' => []]], JSON_THROW_ON_ERROR));
        }
    }

    private function linesHandledByAPlainHandler(): object
    {
        return new class () {
            #[InternalHandler(inputChannelName: 'order.lines', endpointId: 'handleLines')]
            public function handle(): array
            {
                return [];
            }
        };
    }

    private function linesHandledByAPlainHandlerTakingAPayload(): object
    {
        return new class () {
            #[InternalHandler(inputChannelName: 'order.lines', endpointId: 'handleLines')]
            public function handle(array $lines): array
            {
                return [];
            }
        };
    }

    private function linesHandledByASplitter(): object
    {
        return new class () {
            #[Splitter(inputChannelName: 'order.lines', endpointId: 'handleLines')]
            public function handle(): array
            {
                return [];
            }
        };
    }

    private function linesHandledWithAnAudit(): object
    {
        return new class () {
            #[InternalHandler(inputChannelName: 'order.lines', endpointId: 'handleLines')]
            public function handle(): array
            {
                return [];
            }

            #[CommandHandler('order.audit', endpointId: 'audit')]
            public function audit(): void
            {
            }
        };
    }

    /**
     * @param list<array{module: string, entries: list<array<string, mixed>>}> $build
     * @return array<string, array<string, mixed>>
     */
    private function entriesOf(array $build): array
    {
        $entries = [];
        foreach ($build as $module) {
            foreach ($module['entries'] as $entry) {
                $entries[$entry['kind'] . ':' . $entry['name']] = $entry + ['module' => $module['module']];
            }
        }

        return $entries;
    }

    private function record(string $scenario, string $side, object $handlers): void
    {
        $this->application([$handlers::class], [$handlers])->runConsoleCommand('ecotone:build-flow:record', ['scenario' => $scenario, 'location' => $this->location . '/' . $side]);
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

    private function presentation(string $presentation): string
    {
        $ecotone = $this->application([], []);

        $ecotone->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after', 'presentation' => $presentation]);

        return $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
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
            [TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
