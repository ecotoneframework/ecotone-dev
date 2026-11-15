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
final class InternalFlowBuildComparisonTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-internal-flow-build-comparison-' . bin2hex(random_bytes(6));
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
    }

    public function test_a_build_change_every_scenario_shares_is_listed_once_and_left_out_of_the_groups(): void
    {
        $this->record('Handling lines', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines', 'after', $this->linesHandledByASplitter());
        $this->record('Handling lines again', 'before', $this->linesHandledByAPlainHandler());
        $this->record('Handling lines again', 'after', $this->linesHandledWithAnAudit());
        foreach (['before', 'after'] as $side) {
            $stored = json_decode((string) file_get_contents($this->location . '/' . $side . '/handling-lines.json'), true, flags: JSON_THROW_ON_ERROR);
            $again = json_decode((string) file_get_contents($this->location . '/' . $side . '/handling-lines-again.json'), true, flags: JSON_THROW_ON_ERROR);
            $service = ['module' => 'Acme\ReportingModule', 'kind' => 'service', 'name' => 'Acme\Reports', 'class' => $side === 'before' ? 'Acme\Reports' : 'Acme\FasterReports'];
            $stored['build']['ledger'][] = $service;
            $again['build']['ledger'][] = $service;
            file_put_contents($this->location . '/' . $side . '/handling-lines.json', json_encode($stored, JSON_THROW_ON_ERROR));
            file_put_contents($this->location . '/' . $side . '/handling-lines-again.json', json_encode($again, JSON_THROW_ON_ERROR));
        }

        $comparison = $this->comparison();

        self::assertSame(['service:Acme\Reports'], array_keys($this->entriesOf($comparison['everyScenario']['build'])));
        self::assertSame('Acme\ReportingModule', $comparison['everyScenario']['build'][0]['module']);
        self::assertSame('Acme\FasterReports', $comparison['everyScenario']['build'][0]['entries'][0]['after']['class']);
        self::assertCount(2, $comparison['groups']);
        foreach ($comparison['groups'] as $group) {
            self::assertArrayNotHasKey('service:Acme\Reports', $this->entriesOf($group['build']));
        }
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
        $this->application([$handlers::class], [$handlers])->runConsoleCommand('ecotone:internal-flow:record', ['scenario' => $scenario, 'location' => $this->location . '/' . $side]);
    }

    /**
     * @return array<string, mixed>
     */
    private function comparison(): array
    {
        $ecotone = $this->application([], []);

        $ecotone->runConsoleCommand('ecotone:internal-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after', 'presentation' => 'json']);

        return json_decode($ecotone->getInMemoryConsoleWriter()->getWrittenContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     */
    private function application(array $classes, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [...$classes, InternalFlowConsoleCommands::class],
            [...$services, new InternalFlowConsoleCommands(), InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
