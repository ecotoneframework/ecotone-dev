<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests\Tracing;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Dbal\Test\StoredFlowVerifier;
use Ecotone\OpenTelemetry\Test\ExportedSpans;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\MessageFlowGraphVerifier;
use Ecotone\Test\LicenceTesting;
use Monorepo\CrossModuleTests\Fixture\Flow\CrossModuleFlowScenarioCatalogue;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CrossModuleFlowConformanceTest extends TestCase
{
    protected function setUp(): void
    {
        EventSourcingMessagingTestCase::clearDataTables(DbalMessagingTestCase::prepareConnection()->createContext()->getDbalConnection());
    }

    #[DataProviderExternal(CrossModuleFlowScenarioCatalogue::class, 'dataProvider')]
    public function test_every_cross_module_flow_is_traced_exported_stored_and_drawn_alike(FlowScenario $scenario): void
    {
        $recorder = new InMemorySpanRecorder();
        $exporter = new InMemoryExporter();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            $scenario->classesToResolve,
            [
                ...$scenario->services,
                InMemorySpanRecorder::class => $recorder,
                TracerProviderInterface::class => new TracerProvider(new SimpleSpanProcessor($exporter)),
                DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone'),
            ],
            [
                FlowTrackingConfiguration::createWithDefaults(),
                TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class),
                ...$scenario->extensionObjects,
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::TRACING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
        $tracing = $ecotone->getGateway(TracingGateway::class);

        foreach (array_keys($scenario->stages) as $index) {
            self::assertNull($scenario->runStage($index, $ecotone));

            self::assertNull($scenario->mismatchAfterStage($index, $recorder->recordedSpans()));
            self::assertNull($scenario->mismatchAfterStage($index, ExportedSpans::of($exporter)));
            self::assertNull(StoredFlowVerifier::mismatchAfterStage($scenario, $index, $tracing, $recorder->recordedSpans()));
        }
        self::assertNull(MessageFlowGraphVerifier::mismatchIn($ecotone->getMessageFlowGraph(), $recorder->recordedSpans()));
    }
}
