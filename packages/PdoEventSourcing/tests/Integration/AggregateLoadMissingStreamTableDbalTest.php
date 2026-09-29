<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Database\EventStreamTableManager;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class AggregateLoadMissingStreamTableDbalTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public function test_a_missing_event_stream_table_names_the_setup_command_when_an_aggregate_command_loads_its_history(): void
    {
        $ecotone = $this->bootstrapWithoutTheEventStreamTable();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, self::STREAM, null));

        $ecotone->sendCommand(new AddSeatsToScreeningWithoutStreamTable('screening-1', 3));
    }

    public function test_an_aggregate_command_loads_its_history_once_the_database_is_initialized(): void
    {
        $ecotone = $this->bootstrapWithoutTheEventStreamTable();

        $ecotone->initializeDatabase();

        $ecotone->sendCommand(new OpenScreeningWithoutStreamTable('screening-1', 2));
        $ecotone->sendCommand(new AddSeatsToScreeningWithoutStreamTable('screening-1', 3));

        self::assertSame(5, $ecotone->getAggregate(ScreeningWithoutStreamTable::class, 'screening-1')->capacity());
    }

    private function bootstrapWithoutTheEventStreamTable(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                ScreeningWithoutStreamTable::class,
                ScreeningWithoutStreamTableConverter::class,
            ],
            containerOrAvailableServices: [
                self::getConnectionFactory(),
                new ScreeningWithoutStreamTableConverter(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(false),
                    EventSourcingConfiguration::createWithDefaults(),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../',
        );

        $ecotone->getServiceFromContainer(DatabaseSetupManager::class)->drop(EventStreamTableManager::FEATURE_NAME);

        return $ecotone;
    }
}

final readonly class OpenScreeningWithoutStreamTable
{
    public function __construct(public string $screeningId, public int $capacity)
    {
    }
}

final readonly class AddSeatsToScreeningWithoutStreamTable
{
    public function __construct(public string $screeningId, public int $seats)
    {
    }
}

final readonly class ScreeningOpenedWithoutStreamTable
{
    public function __construct(public string $screeningId, public int $capacity)
    {
    }
}

final readonly class SeatsAddedWithoutStreamTable
{
    public function __construct(public string $screeningId, public int $seats)
    {
    }
}

#[EventSourcingAggregate]
#[AggregateType('ScreeningWithoutStreamTable')]
final class ScreeningWithoutStreamTable
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $screeningId;

    private int $capacity = 0;

    #[CommandHandler]
    public static function open(OpenScreeningWithoutStreamTable $command): array
    {
        return [new ScreeningOpenedWithoutStreamTable($command->screeningId, $command->capacity)];
    }

    #[CommandHandler]
    public function addSeats(AddSeatsToScreeningWithoutStreamTable $command): array
    {
        return [new SeatsAddedWithoutStreamTable($command->screeningId, $command->seats)];
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    #[EventSourcingHandler]
    public function applyOpened(ScreeningOpenedWithoutStreamTable $event): void
    {
        $this->screeningId = $event->screeningId;
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function applySeatsAdded(SeatsAddedWithoutStreamTable $event): void
    {
        $this->capacity += $event->seats;
    }
}

final class ScreeningWithoutStreamTableConverter
{
    #[Converter]
    public function fromScreeningOpened(ScreeningOpenedWithoutStreamTable $event): array
    {
        return ['screeningId' => $event->screeningId, 'capacity' => $event->capacity];
    }

    #[Converter]
    public function toScreeningOpened(array $event): ScreeningOpenedWithoutStreamTable
    {
        return new ScreeningOpenedWithoutStreamTable($event['screeningId'], $event['capacity']);
    }

    #[Converter]
    public function fromSeatsAdded(SeatsAddedWithoutStreamTable $event): array
    {
        return ['screeningId' => $event->screeningId, 'seats' => $event->seats];
    }

    #[Converter]
    public function toSeatsAdded(array $event): SeatsAddedWithoutStreamTable
    {
        return new SeatsAddedWithoutStreamTable($event['screeningId'], $event['seats']);
    }
}
