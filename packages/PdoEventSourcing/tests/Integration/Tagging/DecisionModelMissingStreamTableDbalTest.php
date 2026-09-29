<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelMissingStreamTableDbalTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public function test_a_missing_event_stream_table_names_the_setup_command_before_an_aggregate_backed_model_decides_on_no_events(): void
    {
        $ecotone = $this->bootstrapWithoutTheEventStreamTable();

        $refusal = null;
        try {
            $ecotone->sendCommand(new ReserveSeatForMissingStreamTableTest('screening-1'));
        } catch (ConfigurationException $exception) {
            $refusal = $exception;
        }

        self::assertStringContainsString(
            MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, self::STREAM, null),
            (string) $refusal?->getMessage(),
        );
        self::assertSame([], SeatReservationsForMissingStreamTableTest::$observedCapacities);
    }

    public function test_the_same_decision_is_taken_once_the_database_is_initialized(): void
    {
        $ecotone = $this->bootstrapWithoutTheEventStreamTable();

        $ecotone->initializeDatabase();

        $ecotone->sendCommand(new OpenScreeningForMissingStreamTableTest('screening-1', 2));
        $ecotone->sendCommand(new ReserveSeatForMissingStreamTableTest('screening-1'));

        self::assertSame([2], SeatReservationsForMissingStreamTableTest::$observedCapacities);
    }

    protected function setUp(): void
    {
        parent::setUp();
        SeatReservationsForMissingStreamTableTest::$observedCapacities = [];
    }

    private function bootstrapWithoutTheEventStreamTable(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                ScreeningForMissingStreamTableTest::class,
                ScreeningOpenedForMissingStreamTableTest::class,
                SeatReservedForMissingStreamTableTest::class,
                ScreeningCapacityForMissingStreamTableTest::class,
                SeatReservationsForMissingStreamTableTest::class,
                EventsConverterForMissingStreamTableTest::class,
            ],
            containerOrAvailableServices: [
                self::getConnectionFactory(),
                new EventsConverterForMissingStreamTableTest(),
                new SeatReservationsForMissingStreamTableTest(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(false),
                    EventSourcingConfiguration::createWithDefaults(),
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->getServiceFromContainer(DatabaseSetupManager::class)->drop(EventStreamTableManager::FEATURE_NAME);

        return $ecotone;
    }
}

final readonly class OpenScreeningForMissingStreamTableTest
{
    public function __construct(public string $screeningId, public int $capacity)
    {
    }
}

final readonly class ReserveSeatForMissingStreamTableTest
{
    public function __construct(public string $screeningId)
    {
    }
}

final readonly class ScreeningOpenedForMissingStreamTableTest
{
    public function __construct(public string $screeningId, public int $capacity)
    {
    }
}

final readonly class SeatReservedForMissingStreamTableTest
{
    public function __construct(#[EventTag('screening')] public string $screeningId)
    {
    }
}

#[EventSourcingAggregate]
#[AggregateType('MissingStreamTableScreening')]
final class ScreeningForMissingStreamTableTest
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $screeningId;

    private int $capacity = 0;

    #[CommandHandler]
    public static function open(OpenScreeningForMissingStreamTableTest $command): array
    {
        return [new ScreeningOpenedForMissingStreamTableTest($command->screeningId, $command->capacity)];
    }

    #[EventSourcingHandler]
    public function applyOpened(ScreeningOpenedForMissingStreamTableTest $event): void
    {
        $this->screeningId = $event->screeningId;
        $this->capacity = $event->capacity;
    }
}

#[DecisionModel(aggregate: ScreeningForMissingStreamTableTest::class)]
final class ScreeningCapacityForMissingStreamTableTest
{
    private int $capacity = 0;

    #[EventSourcingHandler]
    public function opened(ScreeningOpenedForMissingStreamTableTest $event): void
    {
        $this->capacity = $event->capacity;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}

final class SeatReservationsForMissingStreamTableTest
{
    /** @var int[] */
    public static array $observedCapacities = [];

    #[CommandHandler]
    public function reserve(
        ReserveSeatForMissingStreamTableTest $command,
        ScreeningCapacityForMissingStreamTableTest $screening,
    ): array {
        self::$observedCapacities[] = $screening->capacity();

        return [new SeatReservedForMissingStreamTableTest($command->screeningId)];
    }
}

final class EventsConverterForMissingStreamTableTest
{
    #[Converter]
    public function fromScreeningOpened(ScreeningOpenedForMissingStreamTableTest $event): array
    {
        return ['screeningId' => $event->screeningId, 'capacity' => $event->capacity];
    }

    #[Converter]
    public function toScreeningOpened(array $event): ScreeningOpenedForMissingStreamTableTest
    {
        return new ScreeningOpenedForMissingStreamTableTest($event['screeningId'], $event['capacity']);
    }

    #[Converter]
    public function fromSeatReserved(SeatReservedForMissingStreamTableTest $event): array
    {
        return ['screeningId' => $event->screeningId];
    }

    #[Converter]
    public function toSeatReserved(array $event): SeatReservedForMissingStreamTableTest
    {
        return new SeatReservedForMissingStreamTableTest($event['screeningId']);
    }
}
