<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use function array_filter;
use function array_map;

use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Lite\Test\DatabaseTablesSetUp;
use Ecotone\Messaging\Config\ConfigurationException;

use function implode;

use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
final class DbalDatabaseTablesSetUp implements DatabaseTablesSetUp
{
    /**
     * @param array<string, ConnectionFactory|null> $connectionFactories
     * @param array<string, DbalTableManager[]> $tableManagersByConnection
     */
    public function __construct(
        private array $connectionFactories,
        private array $tableManagersByConnection,
    ) {
    }

    public function setUpEveryTable(): void
    {
        foreach ($this->usedTableManagersByConnection() as $connectionReferenceName => $tableManagers) {
            $connectionFactory = $this->connectionFactories[$connectionReferenceName] ?? null;
            if ($connectionFactory === null) {
                throw ConfigurationException::create(sprintf(
                    "setupDatabaseTables() cannot create the tables of %s: they live on connection '%s', and the test registers no service under that reference. "
                    . "Register the connection factory the test uses - for example containerOrAvailableServices: ['%s' => new DbalConnectionFactory(\$dsn)] - "
                    . 'or keep those features off the database: DbalConfiguration::createWithDefaults()->withDocumentStore(inMemoryDocumentStore: true), '
                    . 'withDeduplication(inMemory: true), withDeadLetter(true, inMemory: true), or EventSourcingConfiguration::createInMemory().',
                    implode(', ', array_map(fn (DbalTableManager $tableManager): string => "'{$tableManager->getFeatureName()}'", $tableManagers)),
                    $connectionReferenceName,
                    $connectionReferenceName,
                ));
            }

            $this->setUp($connectionFactory, $tableManagers);
        }
    }

    public function setUpTablesOfProvidedConnections(): void
    {
        foreach ($this->usedTableManagersByConnection() as $connectionReferenceName => $tableManagers) {
            $connectionFactory = $this->connectionFactories[$connectionReferenceName] ?? null;
            if ($connectionFactory !== null) {
                $this->setUp($connectionFactory, $tableManagers);
            }
        }
    }

    /**
     * @param DbalTableManager[] $tableManagers
     */
    private function setUp(ConnectionFactory $connectionFactory, array $tableManagers): void
    {
        (new DatabaseSetupManager(new DbalReconnectableConnectionFactory($connectionFactory), $tableManagers))->initializeAll();
    }

    /**
     * @return array<string, DbalTableManager[]>
     */
    private function usedTableManagersByConnection(): array
    {
        return array_filter(array_map(
            fn (array $tableManagers): array => array_values(array_filter($tableManagers, fn (DbalTableManager $tableManager): bool => $tableManager->isUsed())),
            $this->tableManagersByConnection,
        ));
    }
}
