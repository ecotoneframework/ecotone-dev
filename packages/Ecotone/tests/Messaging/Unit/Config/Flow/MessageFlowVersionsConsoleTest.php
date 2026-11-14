<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Flow;

use DateTimeImmutable;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Flow\FlowVersionsConfiguration;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Test\LicenceTesting;
use Exception;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * licence Apache-2.0
 * @internal
 */
final class MessageFlowVersionsConsoleTest extends TestCase
{
    private string $location;

    protected function setUp(): void
    {
        $this->location = sys_get_temp_dir() . '/ecotone-flow-versions-' . bin2hex(random_bytes(6));
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

    public function test_a_stored_version_is_listed_with_its_label_and_the_time_it_was_stored(): void
    {
        $ecotone = $this->placingOrders();

        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $ecotone->runConsoleCommand('ecotone:flow:versions');

        self::assertSame(
            [['columnHeaders' => ['Label', 'Stored at', 'Nodes', 'Edges'], 'rows' => [['1.0.0', '2026-03-01T10:00:00+00:00', 2, 1]]]],
            $ecotone->getInMemoryConsoleWriter()->getTables(),
        );
    }

    public function test_a_location_written_relative_to_a_source_directory_is_reported_as_the_directory_it_points_at(): void
    {
        $placing = $this->placingService();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$placing::class],
            [$placing],
            extensionObjects: [FlowVersionsConfiguration::createWithLocation($this->location . '/src/Infrastructure/../../flow-versions')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);

        self::assertSame(["Stored flow version 1.0.0 in {$this->location}/flow-versions/1.0.0.json"], $ecotone->getInMemoryConsoleWriter()->getSuccessLines());
        self::assertFileExists($this->location . '/flow-versions/1.0.0.json');
    }

    public function test_versions_are_listed_from_the_earliest_stored_to_the_latest(): void
    {
        $ecotone = $this->placingOrders();

        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => 'release-b']);
        $ecotone->advanceTimeBy(new TimeSpan(hours: 1));
        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => 'release-a']);
        $ecotone->runConsoleCommand('ecotone:flow:versions');

        self::assertSame(['release-b', 'release-a'], array_column($ecotone->getInMemoryConsoleWriter()->getTables()[0]['rows'], 0));
    }

    public function test_no_versions_are_listed_before_the_first_one_is_stored(): void
    {
        $ecotone = $this->placingOrders();

        $ecotone->runConsoleCommand('ecotone:flow:versions');

        self::assertSame([], $ecotone->getInMemoryConsoleWriter()->getTables()[0]['rows']);
    }

    public function test_a_stored_version_is_a_file_named_after_its_label_holding_the_graph_in_sorted_order(): void
    {
        $ecotone = $this->placingOrders();

        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);

        $stored = json_decode(file_get_contents($this->location . '/1.0.0.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['formatVersion', 'label', 'storedAt', 'graph'], array_keys($stored));
        self::assertSame(1, $stored['formatVersion']);
        self::assertSame('1.0.0', $stored['label']);
        self::assertSame('2026-03-01T10:00:00+00:00', $stored['storedAt']);
        $nodeIds = array_column($stored['graph']['nodes'], 'id');
        $sortedNodeIds = $nodeIds;
        sort($sortedNodeIds, SORT_STRING);
        self::assertSame($sortedNodeIds, $nodeIds);
        self::assertFalse($ecotone->getMessageFlowGraph()->compareTo(MessageFlowGraph::fromArray($stored['graph']))->hasChanges());
    }

    public function test_the_same_application_stores_the_same_graph_every_time(): void
    {
        $this->placingOrders()->runConsoleCommand('ecotone:flow:store', ['label' => 'first']);
        $this->placingOrders()->runConsoleCommand('ecotone:flow:store', ['label' => 'second']);

        self::assertSame(
            json_decode(file_get_contents($this->location . '/first.json'), true, flags: JSON_THROW_ON_ERROR)['graph'],
            json_decode(file_get_contents($this->location . '/second.json'), true, flags: JSON_THROW_ON_ERROR)['graph'],
        );
    }

    public function test_compare_reports_no_changes_between_the_latest_stored_version_and_the_same_code(): void
    {
        $ecotone = $this->placingOrders();
        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);

        $ecotone->runConsoleCommand('ecotone:flow:compare');

        self::assertSame(
            "Message flow from version 1.0.0 to the current code\nNo changes in the message flow.\n",
            $ecotone->getInMemoryConsoleWriter()->getWrittenContent(),
        );
    }

    public function test_compare_reports_what_the_current_code_added_since_the_latest_stored_version(): void
    {
        $this->placingOrders()->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $ecotone = $this->placingAndRefundingOrders();

        $ecotone->runConsoleCommand('ecotone:flow:compare');

        $output = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
        self::assertStringStartsWith("Message flow from version 1.0.0 to the current code\nAdded (", $output);
        self::assertStringContainsString('  + command message:flow.refund', $output);
        self::assertStringContainsString('  + routedTo message:flow.refund -> handler:', $output);
        self::assertStringNotContainsString('Removed (', $output);
    }

    public function test_compare_takes_the_version_stored_last_as_the_latest(): void
    {
        $earlier = $this->placingOrders();
        $earlier->runConsoleCommand('ecotone:flow:store', ['label' => 'zeta']);
        $later = $this->placingAndRefundingOrders();
        $later->advanceTimeBy(new TimeSpan(hours: 1));
        $later->runConsoleCommand('ecotone:flow:store', ['label' => 'alpha']);

        $later->runConsoleCommand('ecotone:flow:compare');

        self::assertSame(
            "Message flow from version alpha to the current code\nNo changes in the message flow.\n",
            $later->getInMemoryConsoleWriter()->getWrittenContent(),
        );
    }

    public function test_compare_reports_the_changes_between_two_stored_versions(): void
    {
        $this->placingOrders()->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $ecotone = $this->placingAndRefundingOrders();
        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.1.0']);

        $ecotone->runConsoleCommand('ecotone:flow:compare', ['from' => '1.1.0', 'to' => '1.0.0']);

        $output = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
        self::assertStringStartsWith("Message flow from version 1.1.0 to version 1.0.0\nRemoved (", $output);
        self::assertStringContainsString('  - command message:flow.refund', $output);
    }

    public function test_compare_as_json_names_both_sides_and_groups_the_changes(): void
    {
        $this->placingOrders()->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $ecotone = $this->placingAndRefundingOrders();

        $ecotone->runConsoleCommand('ecotone:flow:compare', ['format' => 'json']);

        $comparison = json_decode($ecotone->getInMemoryConsoleWriter()->getWrittenContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['from', 'to', 'added', 'removed', 'changed'], array_keys($comparison));
        self::assertSame('1.0.0', $comparison['from']);
        self::assertNull($comparison['to']);
        self::assertContains('message:flow.refund', array_column($comparison['added']['nodes'], 'id'));
        self::assertSame([], $comparison['removed']['nodes']);
    }

    public function test_compare_as_mermaid_draws_the_flow_with_the_added_parts_marked(): void
    {
        $this->placingOrders()->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $ecotone = $this->placingAndRefundingOrders();

        $ecotone->runConsoleCommand('ecotone:flow:compare', ['format' => 'mermaid']);

        $output = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
        self::assertStringStartsWith("flowchart LR\n", $output);
        self::assertMatchesRegularExpression('/^    class n\d+(,n\d+)* added$/m', $output);
    }

    public function test_compare_failing_on_change_reports_the_changes_and_then_fails_naming_how_to_accept_them(): void
    {
        $this->placingOrders()->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
        $ecotone = $this->placingAndRefundingOrders();

        try {
            $ecotone->runConsoleCommand('ecotone:flow:compare', ['failOnChange' => true]);
            self::fail();
        } catch (Exception $exception) {
            self::assertSame(
                'The message flow changed from version 1.0.0 to the current code: 3 added, 0 removed, 0 changed. Accept the new flow by storing it with `ecotone:flow:store <label>` and committing the file, or run ecotone:flow:compare without --failOnChange to only report the changes.',
                $exception->getMessage(),
            );
        }
        self::assertStringContainsString('  + command message:flow.refund', $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_compare_failing_on_change_passes_when_nothing_changed(): void
    {
        $ecotone = $this->placingOrders();
        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);

        $ecotone->runConsoleCommand('ecotone:flow:compare', ['failOnChange' => true]);

        self::assertStringContainsString('No changes in the message flow.', $ecotone->getInMemoryConsoleWriter()->getWrittenContent());
    }

    public function test_compare_refuses_a_label_that_was_never_stored_and_lists_the_stored_ones(): void
    {
        $ecotone = $this->placingOrders();
        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);

        $this->expectExceptionMessage("Flow version `0.9.0` is not stored in {$this->location}. Stored versions: `1.0.0`. Pass one of them, or store the current flow first with `ecotone:flow:store 0.9.0`.");

        $ecotone->runConsoleCommand('ecotone:flow:compare', ['from' => '0.9.0']);
    }

    public function test_compare_refuses_to_run_before_any_version_is_stored(): void
    {
        $ecotone = $this->placingOrders();

        $this->expectExceptionMessage("ecotone:flow:compare has no stored flow version to compare with, because {$this->location} holds none. Store the current flow first with `ecotone:flow:store <label>`, for example on the branch you compare against, or name a stored version with --from.");

        $ecotone->runConsoleCommand('ecotone:flow:compare');
    }

    public function test_compare_refuses_a_format_it_cannot_produce(): void
    {
        $ecotone = $this->placingOrders();
        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);

        $this->expectExceptionMessage('ecotone:flow:compare was asked for format `yaml`, which it cannot produce. Pass --format=text for a list of the changes, --format=json for the added, removed and changed nodes and edges, or --format=mermaid for a Mermaid flowchart with the changes marked.');

        $ecotone->runConsoleCommand('ecotone:flow:compare', ['format' => 'yaml']);
    }

    public function test_store_refuses_a_label_that_is_already_stored(): void
    {
        $ecotone = $this->placingOrders();
        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);

        $this->expectExceptionMessage("Flow version `1.0.0` is already stored in {$this->location}/1.0.0.json, and a stored version is not replaced. Store the current flow under another label, or delete that file first to store it again.");

        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '1.0.0']);
    }

    public function test_store_refuses_a_label_that_cannot_be_a_file_name(): void
    {
        $ecotone = $this->placingOrders();

        $this->expectExceptionMessage('Flow version label `../outside` cannot be used, because the label becomes the file name of the stored version. Use letters, digits, dots, dashes and underscores only, starting with a letter or a digit, for example `1.4.0` or a commit hash.');

        $ecotone->runConsoleCommand('ecotone:flow:store', ['label' => '../outside']);
    }

    public function test_commands_refuse_to_run_without_a_configured_location_and_name_the_configuration(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
        $messages = [];

        foreach (['ecotone:flow:store' => ['label' => '1.0.0'], 'ecotone:flow:versions' => [], 'ecotone:flow:compare' => []] as $command => $parameters) {
            try {
                $ecotone->runConsoleCommand($command, $parameters);
            } catch (Exception $exception) {
                $messages[$command] = $exception->getMessage();
            }
        }

        self::assertSame(
            [
                'ecotone:flow:store' => "ecotone:flow:store needs a location to keep flow versions in, but none is configured. Return FlowVersionsConfiguration::createWithLocation(__DIR__ . '/../flow-versions') from a #[ServiceContext] method, pointing at a directory committed with the application.",
                'ecotone:flow:versions' => "ecotone:flow:versions needs a location to keep flow versions in, but none is configured. Return FlowVersionsConfiguration::createWithLocation(__DIR__ . '/../flow-versions') from a #[ServiceContext] method, pointing at a directory committed with the application.",
                'ecotone:flow:compare' => "ecotone:flow:compare needs a location to keep flow versions in, but none is configured. Return FlowVersionsConfiguration::createWithLocation(__DIR__ . '/../flow-versions') from a #[ServiceContext] method, pointing at a directory committed with the application.",
            ],
            $messages,
        );
    }

    public function test_a_relative_location_is_refused_at_bootstrap(): void
    {
        $this->expectExceptionMessage("FlowVersionsConfiguration points at location `flow-versions`, which is relative, so it would resolve differently for a console command and a web request. Pass an absolute path, for example FlowVersionsConfiguration::createWithLocation(__DIR__ . '/../flow-versions').");

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [FlowVersionsConfiguration::createWithLocation('flow-versions')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    public function test_a_second_location_is_refused_at_bootstrap(): void
    {
        $this->expectExceptionMessage('FlowVersionsConfiguration was registered 2 times, but an application keeps its flow versions in exactly one location. Keep a single #[ServiceContext] returning FlowVersionsConfiguration::createWithLocation(...) and remove the others.');

        EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [FlowVersionsConfiguration::createWithLocation($this->location), FlowVersionsConfiguration::createWithLocation($this->location . '-other')],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_a_location_is_refused_at_bootstrap_without_an_enterprise_licence(): void
    {
        $this->expectExceptionMessage('Flow versions are available only as part of Ecotone Enterprise. Provide a licence key, or remove the FlowVersionsConfiguration from your #[ServiceContext]. See https://docs.ecotone.tech/enterprise for details.');

        EcotoneLite::bootstrapFlowTesting(extensionObjects: [FlowVersionsConfiguration::createWithLocation($this->location)], enabledModules: []);
    }

    private function placingOrders(): FlowTestSupport
    {
        return $this->bootstrap($this->placingService());
    }

    private function placingAndRefundingOrders(): FlowTestSupport
    {
        return $this->bootstrap($this->placingService(), $this->refundingService());
    }

    private function bootstrap(object ...$services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            array_map(fn (object $service): string => $service::class, $services),
            $services,
            extensionObjects: [FlowVersionsConfiguration::createWithLocation($this->location)],
            enabledModules: [],
            initialTime: new DateTimeImmutable('2026-03-01T10:00:00+00:00'),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function placingService(): object
    {
        return new class () {
            #[CommandHandler('flow.place')]
            public function place(): void
            {
            }
        };
    }

    private function refundingService(): object
    {
        return new class () {
            #[CommandHandler('flow.refund')]
            public function refund(): void
            {
            }
        };
    }
}
