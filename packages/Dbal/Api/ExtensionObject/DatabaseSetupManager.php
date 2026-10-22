<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\ExtensionObject;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Enqueue\ConnectionFactory;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class DatabaseSetupManager implements DefinedObject
{
    /**
     * @param DbalTableManager[] $tableManagers
     */
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private array $tableManagers = [],
    ) {
    }

    /**
     * @return string[]
     */
    public function getFeatureNames(bool $onlyUsed = true): array
    {
        return array_map(
            fn (DbalTableManager $manager) => $manager->getFeatureName(),
            $this->getManagers($onlyUsed)
        );
    }

    /**
     * @return string[]
     */
    public function getCreateSqlStatements(bool $onlyUsed = true): array
    {
        return $this->createSqlStatementsOf($this->getManagers($onlyUsed));
    }

    /**
     * @return string[]
     */
    public function getDropSqlStatements(bool $onlyUsed = true): array
    {
        return $this->dropSqlStatementsOf($this->getManagers($onlyUsed));
    }

    public function initializeAll(bool $onlyUsed = true): void
    {
        foreach ($this->connections() as $connection) {
            foreach ($this->getManagers($onlyUsed) as $manager) {
                if (! $manager->isInitialized($connection)) {
                    $manager->createTable($connection);
                }
            }
        }
    }

    public function dropAll(bool $onlyUsed = true): void
    {
        foreach ($this->connections() as $connection) {
            foreach ($this->getManagers($onlyUsed) as $manager) {
                $manager->dropTable($connection);
            }
        }
    }

    public function initialize(string $featureName): void
    {
        $manager = $this->findManager($featureName);

        foreach ($this->connections() as $connection) {
            if (! $manager->isInitialized($connection)) {
                $manager->createTable($connection);
            }
        }
    }

    public function drop(string $featureName): void
    {
        $manager = $this->findManager($featureName);

        foreach ($this->connections() as $connection) {
            $manager->dropTable($connection);
        }
    }

    /**
     * @param string[] $featureNames
     * @return string[]
     */
    public function getCreateSqlStatementsForFeatures(array $featureNames): array
    {
        return $this->createSqlStatementsOf(array_map(fn (string $featureName) => $this->findManager($featureName), $featureNames));
    }

    /**
     * @param string[] $featureNames
     * @return string[]
     */
    public function getDropSqlStatementsForFeatures(array $featureNames): array
    {
        return $this->dropSqlStatementsOf(array_map(fn (string $featureName) => $this->findManager($featureName), $featureNames));
    }

    /**
     * @return array<string, bool>
     */
    public function getInitializationStatus(bool $onlyUsed = true): array
    {
        $connections = $this->connections();
        $status = [];

        foreach ($this->getManagers($onlyUsed) as $manager) {
            $status[$manager->getFeatureName()] = true;
            foreach ($connections as $connection) {
                if (! $manager->isInitialized($connection)) {
                    $status[$manager->getFeatureName()] = false;
                    break;
                }
            }
        }

        return $status;
    }

    /**
     * @return array<string, bool>
     */
    public function getInitializationStatusOnCurrentConnection(bool $onlyUsed = true): array
    {
        $connection = $this->singleConnection();
        $status = [];

        foreach ($this->getManagers($onlyUsed) as $manager) {
            $status[$manager->getFeatureName()] = $manager->isInitialized($connection);
        }

        return $status;
    }

    public function getUsageStatus(): array
    {
        $status = [];

        foreach ($this->tableManagers as $manager) {
            $status[$manager->getFeatureName()] = $manager->isUsed();
        }

        return $status;
    }

    /**
     * @return DbalTableManager[]
     */
    private function getManagers(bool $onlyUsed): array
    {
        if (! $onlyUsed) {
            return $this->tableManagers;
        }

        return array_filter(
            $this->tableManagers,
            fn (DbalTableManager $manager) => $manager->isUsed()
        );
    }

    private function findManager(string $featureName): DbalTableManager
    {
        foreach ($this->tableManagers as $manager) {
            if ($manager->getFeatureName() === $featureName) {
                return $manager;
            }
        }

        throw new InvalidArgumentException("Table manager not found for feature: {$featureName}");
    }

    /**
     * @param DbalTableManager[] $managers
     * @return string[]
     */
    private function createSqlStatementsOf(array $managers): array
    {
        return $this->sqlStatementsOnEveryConnection(function (Connection $connection) use ($managers): array {
            $statements = [];
            foreach ($managers as $manager) {
                $sql = $manager->getCreateTableSql($connection);
                $statements = array_merge($statements, is_array($sql) ? $sql : [$sql]);
            }

            return $statements;
        });
    }

    /**
     * @param DbalTableManager[] $managers
     * @return string[]
     */
    private function dropSqlStatementsOf(array $managers): array
    {
        return $this->sqlStatementsOnEveryConnection(
            fn (Connection $connection): array => array_values(array_map(fn (DbalTableManager $manager) => $manager->getDropTableSql($connection), $managers))
        );
    }

    /**
     * @param callable(Connection): string[] $statementsOn
     * @return string[]
     */
    private function sqlStatementsOnEveryConnection(callable $statementsOn): array
    {
        if (! $this->isMultiTenant()) {
            return $statementsOn($this->singleConnection());
        }

        $statements = [];
        foreach ($this->connections() as $connectionReferenceName => $connection) {
            $statements[] = "-- connection '{$connectionReferenceName}'";
            $statements = array_merge($statements, $statementsOn($connection));
        }

        return $statements;
    }

    /**
     * @return array<string, Connection>
     */
    private function connections(): array
    {
        if ($this->isMultiTenant()) {
            return $this->multiTenantConnectionFactory()->databaseConnectionsOfEveryTenant();
        }

        return ['' => $this->singleConnection()];
    }

    private function isMultiTenant(): bool
    {
        return $this->unwrappedConnectionFactory() instanceof MultiTenantConnectionFactory;
    }

    private function multiTenantConnectionFactory(): MultiTenantConnectionFactory
    {
        $connectionFactory = $this->unwrappedConnectionFactory();
        assert($connectionFactory instanceof MultiTenantConnectionFactory);

        return $connectionFactory;
    }

    private function unwrappedConnectionFactory(): \Interop\Queue\ConnectionFactory
    {
        return $this->connectionFactory instanceof DbalReconnectableConnectionFactory
            ? $this->connectionFactory->getWrappedConnectionFactory()
            : $this->connectionFactory;
    }

    private function singleConnection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        return $context->getDbalConnection();
    }

    public function getDefinition(): Definition
    {
        $tableManagerDefinitions = array_map(
            fn (DbalTableManager $manager) => $manager->getDefinition(),
            $this->tableManagers
        );

        return new Definition(
            self::class,
            [
                new Definition(DbalReconnectableConnectionFactory::class, [
                    $this->connectionFactory,
                ]),
                $tableManagerDefinitions,
            ]
        );
    }
}
