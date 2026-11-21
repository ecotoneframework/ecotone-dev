<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
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
final class BuildFlowBaselineTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-build-flow-baseline-' . bin2hex(random_bytes(6));
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

    public function test_snapshot_command_replaces_a_location_with_one_file_per_catalogue_scenario_and_the_same_bytes_when_run_again(): void
    {
        $tooling = $this->tooling();

        mkdir($this->location . '/second', recursive: true);
        file_put_contents($this->location . '/second/a-scenario-that-was-removed.json', '{}');
        file_put_contents($this->location . '/second/notes.txt', 'kept');

        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location . '/first']);
        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location . '/second']);

        $first = $this->filesIn($this->location . '/first');
        self::assertCount(count(FlowScenarioCatalogue::all()) + 1, $first);
        $second = $this->filesIn($this->location . '/second');
        self::assertSame('kept', $second['notes.txt']);
        unset($second['notes.txt']);
        self::assertSame($first, $second);
        self::assertArrayHasKey('projection-rebuilt-asynchronously.json', $first);
        $rebuild = json_decode($first['projection-rebuilt-asynchronously.json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('projection rebuilt asynchronously', $rebuild['scenario']);
        self::assertContains('receive rebuild', array_column($rebuild['trace'], 'name'));
    }

    public function test_the_catalogue_holds_an_asynchronous_backfill_of_two_batches_that_reports_each_batch(): void
    {
        $tooling = $this->tooling();
        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location]);

        $twoBatches = json_decode($this->filesIn($this->location)['tag-partitioned-projection-backfilled-asynchronously-in-two-batches.json'], true, flags: JSON_THROW_ON_ERROR);
        $steps = array_map(fn (array $step): string => str_repeat('  ', $step['depth']) . $step['name'], $twoBatches['trace']);

        self::assertSame(
            [
                'backfill course_seats',
                '  send backfill',
                '    receive backfill',
                '      process partition batch course_seats',
                '        process class@anonymous::whenDefined',
                '      send backfill',
                '        receive backfill',
                '  send backfill',
                '    receive backfill',
                '      process partition batch course_seats',
                '        process class@anonymous::whenDefined',
                '      send backfill',
                '        receive backfill',
            ],
            $steps,
        );
    }

    public function test_wiring_every_application_gets_is_stored_once_and_compared_as_part_of_every_scenario(): void
    {
        $tooling = $this->tooling();
        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location . '/before']);
        $files = $this->filesIn($this->location . '/before');
        $shared = json_decode($files['shared.json'], true, flags: JSON_THROW_ON_ERROR);
        $rebuild = json_decode($files['projection-rebuilt-asynchronously.json'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['formatVersion', 'wiring', 'build'], array_keys($shared));
        self::assertSame(['ledger', 'chains'], array_keys($shared['build']));
        self::assertSame(['ledger', 'chains', 'withoutLedger', 'withoutChains'], array_keys($rebuild['build']));
        $names = fn (array $ledger): array => array_map(fn (array $entry): string => $entry['kind'] . ':' . $entry['name'], $ledger);
        self::assertContains('gateway:Ecotone\\Api\\Gateway\\CommandBus::send', $names($shared['build']['ledger']));
        self::assertNotContains('gateway:Ecotone\\Api\\Gateway\\CommandBus::send', $names($rebuild['build']['ledger']));
        self::assertContains('handler:projecting_manager_endpoint:orders', $names($rebuild['build']['ledger']));
        self::assertContains('projecting_manager_endpoint:orders', array_column($rebuild['build']['chains'], 'handler'));
        self::assertNotContains('projecting_manager_endpoint:orders', array_column($shared['build']['chains'], 'handler'));
        self::assertContains('gateway:Ecotone\\Api\\Gateway\\CommandBus::send', array_column($shared['wiring']['nodes'], 'id'));
        self::assertNotContains('gateway:Ecotone\\Api\\Gateway\\CommandBus::send', array_column($rebuild['wiring']['nodes'], 'id'));
        self::assertContains('projection:orders', array_column($rebuild['wiring']['nodes'], 'id'));
        self::assertContains('channel:rebuild', array_column($rebuild['wiring']['nodes'], 'id'));
        self::assertSame(['nodes', 'edges', 'withoutNodes', 'withoutEdges'], array_keys($rebuild['wiring']));
        self::assertLessThan(strlen($files['shared.json']) / 2, strlen($files['projection-rebuilt-asynchronously.json']));

        mkdir($this->location . '/after');
        foreach ($files as $name => $content) {
            file_put_contents($this->location . '/after/' . $name, $name === 'shared.json' ? str_replace('"label":"CommandBus::send"', '"label":"CommandBus::dispatch"', $content) : $content);
        }
        $tooling->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/after', 'presentation' => 'json']);
        $comparison = json_decode($tooling->getInMemoryConsoleWriter()->getWrittenContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(1, $comparison['groups']);
        self::assertCount(count(FlowScenarioCatalogue::all()), $comparison['groups'][0]['scenarios']);
        self::assertSame(['gateway:Ecotone\\Api\\Gateway\\CommandBus::send'], array_column(array_column($comparison['groups'][0]['wiring']['changed']['nodes'], 'after'), 'id'));
    }

    public function test_check_command_passes_on_snapshots_the_code_produces_and_names_every_snapshot_that_differs(): void
    {
        $tooling = $this->tooling();
        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location]);

        $tooling->runConsoleCommand('ecotone:build-flow:check', ['location' => $this->location]);

        self::assertStringContainsString('flow snapshots in ' . $this->location . ' match what the code produces', implode("\n", $tooling->getInMemoryConsoleWriter()->getSuccessLines()));

        file_put_contents($this->location . '/projection-rebuilt-asynchronously.json', str_replace('receive rebuild', 'receive something-else', (string) file_get_contents($this->location . '/projection-rebuilt-asynchronously.json')));
        unlink($this->location . '/projection-backfilled-asynchronously.json');
        file_put_contents($this->location . '/a-scenario-that-was-removed.json', '{}');
        file_put_contents($this->location . '/shared.json', str_replace('CommandBus::send', 'CommandBus::dispatch', (string) file_get_contents($this->location . '/shared.json')));

        $this->expectExceptionMessage(
            'The flow snapshots in ' . $this->location . ' are not what the code produces: differs: projection-rebuilt-asynchronously.json, shared.json; missing: projection-backfilled-asynchronously.json; no longer produced: a-scenario-that-was-removed.json. '
            . 'If the message flow was meant to change, leave the snapshots as they are: the pull request comment shows the change, and main\'s CI commits the new snapshots after the merge; `php bin/build-flow.php compare --presentation=build --from=' . $this->location . '` shows it locally as the comment does. If it was not meant to change, the change altered the framework\'s wiring or traced spans by accident.'
        );

        $tooling->runConsoleCommand('ecotone:build-flow:check', ['location' => $this->location]);
    }

    public function test_check_command_refuses_a_location_that_does_not_exist_with_the_command_that_writes_it(): void
    {
        $this->expectExceptionMessage("Flow snapshots were expected in {$this->location}, but that directory does not exist. Pass the directory the snapshots were written to, or write them first with `php bin/build-flow.php snapshot --location={$this->location}`.");

        $this->tooling()->runConsoleCommand('ecotone:build-flow:check', ['location' => $this->location]);
    }

    public function test_comparing_with_a_committed_baseline_this_checkout_lacks_says_main_commits_it_and_how_to_snapshot_the_base(): void
    {
        $baseline = $this->location . '/packages/Ecotone/tests/BuildFlow/baseline';
        $tooling = $this->tooling();
        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location . '/now']);

        $this->expectExceptionMessage("Flow snapshots were expected in {$baseline}, the committed baseline, but this checkout has none. A pull request leaves the baseline alone: main's CI commits it after the merge, so do not write it here. Snapshot the base instead, into a directory of your own: on main, run `php bin/build-flow.php snapshot --location=var/build-flow-main`. Then, on your branch, compare with it: `php bin/build-flow.php compare --presentation=build --from=var/build-flow-main`. Check compares a directory with what the checked-out code produces, so `php bin/build-flow.php check --location=var/build-flow-main` passes on main and, on your branch, names what your change alters. Once main's CI has committed the baseline, merge main and the defaults work again.");

        $tooling->runConsoleCommand('ecotone:build-flow:compare', ['from' => $baseline, 'to' => $this->location . '/now', 'presentation' => 'build']);
    }

    public function test_checking_a_committed_baseline_this_checkout_lacks_says_main_commits_it_and_how_to_snapshot_the_base(): void
    {
        $baseline = $this->location . '/packages/Ecotone/tests/BuildFlow/baseline';

        $this->expectExceptionMessage("Flow snapshots were expected in {$baseline}, the committed baseline, but this checkout has none. A pull request leaves the baseline alone: main's CI commits it after the merge, so do not write it here. Snapshot the base instead, into a directory of your own: on main, run `php bin/build-flow.php snapshot --location=var/build-flow-main`. Then, on your branch, compare with it: `php bin/build-flow.php compare --presentation=build --from=var/build-flow-main`. Check compares a directory with what the checked-out code produces, so `php bin/build-flow.php check --location=var/build-flow-main` passes on main and, on your branch, names what your change alters. Once main's CI has committed the baseline, merge main and the defaults work again.");

        $this->tooling()->runConsoleCommand('ecotone:build-flow:check', ['location' => $baseline]);
    }

    public function test_snapshot_command_refuses_a_location_it_cannot_write_a_snapshot_file_into(): void
    {
        mkdir($this->location . '/shared.json', recursive: true);

        $this->expectExceptionMessage('Flow snapshot file ' . $this->location . '/shared.json could not be written. Make the location ' . $this->location . ' writable for the user running this command, or pass another --location.');

        $this->tooling()->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location]);
    }

    public function test_compare_command_refuses_a_shared_file_without_wiring_and_build(): void
    {
        mkdir($this->location . '/before', recursive: true);
        file_put_contents($this->location . '/before/shared.json', '{}');

        $this->expectExceptionMessage('Shared file ' . $this->location . '/before/shared.json does not hold `wiring` and `build`, so it was not written by this version of the build flow tooling. Restore it from version control, or regenerate the snapshots of that directory with `php bin/build-flow.php snapshot --location=' . $this->location . '/before`.');

        $this->tooling()->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location . '/before', 'to' => $this->location . '/before', 'presentation' => 'json']);
    }

    public function test_compare_command_refuses_a_snapshot_whose_execution_steps_have_no_depth_and_label(): void
    {
        $tooling = $this->tooling();
        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location]);
        $file = $this->location . '/projection-rebuilt-asynchronously.json';
        $snapshot = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $snapshot['execution'][0]['steps'] = [['step' => 'written before steps had a depth and a label']];
        file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage('Flow snapshot file ' . $file . ' holds steps of `execution` without a depth and a label, so it was written by an earlier version of the build flow tooling. Regenerate the snapshots of that directory with `php bin/build-flow.php snapshot --location=' . $this->location . '`.');

        $tooling->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location, 'to' => $this->location, 'presentation' => 'json']);
    }

    public function test_compare_command_refuses_a_snapshot_written_by_another_format_naming_both_formats(): void
    {
        $tooling = $this->tooling();
        $tooling->runConsoleCommand('ecotone:build-flow:snapshot', ['location' => $this->location]);
        $file = $this->location . '/projection-rebuilt-asynchronously.json';
        $snapshot = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $snapshot['formatVersion'] = 2;
        file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage('Flow snapshot file ' . $file . ' was written by format 2, this code reads format 3, so the two cannot be compared: a comparison across a snapshot format change cannot be drawn. Regenerate the snapshots of that directory with `php bin/build-flow.php snapshot --location=' . $this->location . '`.');

        $tooling->runConsoleCommand('ecotone:build-flow:compare', ['from' => $this->location, 'to' => $this->location, 'presentation' => 'json']);
    }

    private function tooling(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [BuildFlowConsoleCommands::class],
            [new BuildFlowConsoleCommands(), new InMemorySpanRecorder()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    /**
     * @return array<string, string>
     */
    private function filesIn(string $directory): array
    {
        $files = [];
        foreach (scandir($directory) as $name) {
            if (is_file($directory . '/' . $name)) {
                $files[$name] = (string) file_get_contents($directory . '/' . $name);
            }
        }

        return $files;
    }
}
