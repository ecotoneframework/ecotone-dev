<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\ExtensionObject;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\ConnectionFactory;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class DatabaseSetupManager implements DefinedObject
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
     * @return string[] List of feature names that require database tables
     */
    public function getFeatureNames(bool $onlyUsed = true): array
    {
        return array_map(
            fn (DbalTableManager $manager) => $manager->getFeatureName(),
            $this->getManagers($onlyUsed)
        );
    }

    /**
     * @return string[] SQL statements to create all tables
     */
    public function getCreateSqlStatements(bool $onlyUsed = true): array
    {
        $connection = $this->getConnection();
        $statements = [];

        foreach ($this->getManagers($onlyUsed) as $manager) {
            $sql = $manager->getCreateTableSql($connection);
            if (is_array($sql)) {
                $statements = array_merge($statements, $sql);
            } else {
                $statements[] = $sql;
            }
        }

        return $statements;
    }

    /**
     * @return string[] SQL statements to drop all tables
     */
    public function getDropSqlStatements(bool $onlyUsed = true): array
    {
        $connection = $this->getConnection();
        $statements = [];

        foreach ($this->getManagers($onlyUsed) as $manager) {
            $statements[] = $manager->getDropTableSql($connection);
        }

        return $statements;
    }

    public function initializeAll(bool $onlyUsed = true): void
    {
        $connection = $this->getConnection();

        foreach ($this->getManagers($onlyUsed) as $manager) {
            if ($manager->isInitialized($connection)) {
                continue;
            }

            $manager->createTable($connection);
        }
    }

    public function dropAll(bool $onlyUsed = true): void
    {
        $connection = $this->getConnection();

        foreach ($this->getManagers($onlyUsed) as $manager) {
            $manager->dropTable($connection);
        }
    }

    public function initialize(string $featureName): void
    {
        $manager = $this->findManager($featureName);
        $connection = $this->getConnection();

        if ($manager->isInitialized($connection)) {
            return;
        }

        $manager->createTable($connection);
    }

    public function drop(string $featureName): void
    {
        $manager = $this->findManager($featureName);
        $connection = $this->getConnection();
        $manager->dropTable($connection);
    }

    /**
     * @param string[] $featureNames
     * @return string[] SQL statements to create tables for specified features
     */
    public function getCreateSqlStatementsForFeatures(array $featureNames): array
    {
        $connection = $this->getConnection();
        $statements = [];

        foreach ($featureNames as $featureName) {
            $manager = $this->findManager($featureName);
            $sql = $manager->getCreateTableSql($connection);
            if (is_array($sql)) {
                $statements = array_merge($statements, $sql);
            } else {
                $statements[] = $sql;
            }
        }

        return $statements;
    }

    /**
     * @param string[] $featureNames
     * @return string[] SQL statements to drop tables for specified features
     */
    public function getDropSqlStatementsForFeatures(array $featureNames): array
    {
        $connection = $this->getConnection();
        $statements = [];

        foreach ($featureNames as $featureName) {
            $manager = $this->findManager($featureName);
            $statements[] = $manager->getDropTableSql($connection);
        }

        return $statements;
    }

    /**
     * @return array<string, bool> Map of feature name to initialization status
     */
    public function getInitializationStatus(bool $onlyUsed = true): array
    {
        $connection = $this->getConnection();
        $status = [];

        foreach ($this->getManagers($onlyUsed) as $manager) {
            $status[$manager->getFeatureName()] = $manager->isInitialized($connection);
        }

        return $status;
    }

    /**
     * @return array<string, bool> Map of feature name to usage status
     */
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
            // Return all managers when onlyUsed is false
            return $this->tableManagers;
        }

        // Return only used managers when onlyUsed is true (default)
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

    private function getConnection(): Connection
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
