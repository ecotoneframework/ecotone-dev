<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandResultSet;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Gateway\ConsoleCommandRunner;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream\PlaceSecondaryOrder;
use Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream\SecondaryOrder;
use Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream\SecondaryOrderConverter;

/**
 * licence Apache-2.0
 * @internal
 */
final class EventStreamMigrationMultiConnectionTest extends EventSourcingMessagingTestCase
{
    public function test_status_lists_secondary_connection_event_stream_table_with_its_connection(): void
    {
        $ecotone = $this->bootstrapWithSecondaryStream();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', []);

        self::assertSame(['Feature', 'Connection', 'Used', 'Initialized'], $result->getColumnHeaders());
        self::assertNotNull($this->findRow($result, 'event_stream', SecondaryOrder::CONNECTION_REFERENCE));
    }

    public function test_sql_includes_secondary_connection_event_stream_create_statement(): void
    {
        $ecotone = $this->bootstrapWithSecondaryStream();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['sql' => true]);

        $allSql = implode(' ', array_column($result->getRows(), 0));
        self::assertStringContainsString(SecondaryOrder::STREAM, $allSql);
    }

    public function test_missing_lists_secondary_connection_event_stream_table(): void
    {
        $ecotone = $this->bootstrapWithSecondaryStream();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['missing' => true]);

        self::assertSame(['Feature', 'Connection'], $result->getColumnHeaders());
        self::assertNotNull($this->findRow($result, 'event_stream', SecondaryOrder::CONNECTION_REFERENCE));
    }

    public function test_initialize_creates_secondary_connection_stream_table_on_its_own_connection(): void
    {
        $ecotone = $this->bootstrapWithSecondaryStream();

        $primaryConnection = $this->connectionForTenantA()->createContext()->getDbalConnection();
        $secondaryConnection = $this->connectionForTenantB()->createContext()->getDbalConnection();

        self::assertFalse(self::tableExists($secondaryConnection, SecondaryOrder::STREAM));

        $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', ['initialize' => true]);

        self::assertTrue(self::tableExists($secondaryConnection, SecondaryOrder::STREAM));
        self::assertFalse(self::tableExists($primaryConnection, SecondaryOrder::STREAM));
    }

    public function test_connection_option_scopes_setup_to_one_connection(): void
    {
        $ecotone = $this->bootstrapWithSecondaryStream();

        $result = $this->executeConsoleCommand($ecotone, 'ecotone:migration:database:setup', [
            'connection' => SecondaryOrder::CONNECTION_REFERENCE,
        ]);

        foreach ($result->getRows() as $row) {
            self::assertSame(SecondaryOrder::CONNECTION_REFERENCE, $row[1]);
        }
        self::assertNotNull($this->findRow($result, 'event_stream', SecondaryOrder::CONNECTION_REFERENCE));
    }

    public function test_missing_table_exception_names_the_secondary_connection_and_correct_command(): void
    {
        $ecotone = $this->bootstrapWithSecondaryStream();

        try {
            $ecotone->sendCommandWithRouting('secondaryOrder.place', new PlaceSecondaryOrder('order-1'));
            self::fail('Expected ConfigurationException was not thrown.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("on connection '" . SecondaryOrder::CONNECTION_REFERENCE . "'", $exception->getMessage());
            self::assertStringContainsString(
                "getManagerFor('" . SecondaryOrder::CONNECTION_REFERENCE . "')",
                $exception->getMessage()
            );
            self::assertStringContainsString("initialize('event_stream')", $exception->getMessage());
        }
    }

    private function bootstrapWithSecondaryStream(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [SecondaryOrder::class, SecondaryOrderConverter::class],
            containerOrAvailableServices: [
                new SecondaryOrderConverter(),
                DbalConnectionFactory::class => $this->connectionForTenantA(),
                SecondaryOrder::CONNECTION_REFERENCE => $this->connectionForTenantB(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(false)]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );
    }

    private function executeConsoleCommand(FlowTestSupport $ecotone, string $commandName, array $parameters): ConsoleCommandResultSet
    {
        /** @var ConsoleCommandRunner $runner */
        $runner = $ecotone->getGateway(ConsoleCommandRunner::class);

        return $runner->execute($commandName, $parameters);
    }

    private function findRow(ConsoleCommandResultSet $result, string $feature, string $connection): ?array
    {
        foreach ($result->getRows() as $row) {
            if ($row[0] === $feature && $row[1] === $connection) {
                return $row;
            }
        }

        return null;
    }
}
