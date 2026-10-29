<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Dbal\Test\StoredFlowVerifier;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FlowTrackingScenarioConformanceTest extends DbalMessagingTestCase
{
    public static function scenariosOnEveryStore(): iterable
    {
        foreach (['in-memory', 'dbal'] as $implementation) {
            foreach (FlowScenarioCatalogue::all() as $scenario) {
                yield "{$scenario->name} [{$implementation}]" => [$scenario, $implementation];
            }
        }
    }

    #[DataProvider('scenariosOnEveryStore')]
    public function test_every_catalogued_flow_stores_its_spans_except_failed_ones_and_completes_once_no_work_is_pending_and_nothing_failed(FlowScenario $scenario, string $implementation): void
    {
        $recorder = new InMemorySpanRecorder();
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: $scenario->classesToResolve,
            containerOrAvailableServices: [...$scenario->services, InMemorySpanRecorder::class => $recorder, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($implementation === 'in-memory'),
                TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class),
                ...$scenario->extensionObjects,
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
        )->setupDatabaseTables(initializeProjections: false);
        $recorder->clear();
        $tracing = $ecotone->getGateway(TracingGateway::class);

        foreach (array_keys($scenario->stages) as $index) {
            self::assertNull($scenario->runStage($index, $ecotone));

            self::assertNull(StoredFlowVerifier::mismatchAfterStage($scenario, $index, $tracing, $recorder->recordedSpans()));
        }
    }
}
