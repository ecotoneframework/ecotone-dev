<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The flow snapshots in ' . $this->location . ' are not what the code produces: differs: projection-rebuilt-asynchronously.json, shared.json; missing: projection-backfilled-asynchronously.json; no longer produced: a-scenario-that-was-removed.json. '
            . 'If the message flow was meant to change, regenerate the snapshots with `php bin/build-flow.php snapshot` and commit them; `php bin/build-flow.php compare` shows what changed. If it was not, the change altered the framework\'s wiring or traced steps by accident.'
        );

        $tooling->runConsoleCommand('ecotone:build-flow:check', ['location' => $this->location]);
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
