<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;

/**
 * Manages database setup and teardown for all registered table managers.
 *
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
    public function getFeatureNames(): array
    {
        return array_map(
            fn (DbalTableManager $manager) => $manager->getFeatureName(),
            $this->tableManagers
        );
    }

    /**
     * @return string[] SQL statements to create all tables
     */
    public function getCreateSqlStatements(): array
    {
        $connection = $this->getConnection();
        $statements = [];

        foreach ($this->tableManagers as $manager) {
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
    public function getDropSqlStatements(): array
    {
        $connection = $this->getConnection();
        $statements = [];

        foreach ($this->tableManagers as $manager) {
            $statements[] = $manager->getDropTableSql($connection);
        }

        return $statements;
    }

    /**
     * Creates all tables.
     */
    public function initializeAll(): void
    {
        $connection = $this->getConnection();

        foreach ($this->tableManagers as $manager) {
            $manager->createTable($connection);
        }
    }

    /**
     * Drops all tables.
     */
    public function dropAll(): void
    {
        $connection = $this->getConnection();

        foreach ($this->tableManagers as $manager) {
            $manager->dropTable($connection);
        }
    }

    /**
     * Returns initialization status for each table manager.
     *
     * @return array<string, bool> Map of feature name to initialization status
     */
    public function getInitializationStatus(): array
    {
        $connection = $this->getConnection();
        $status = [];

        foreach ($this->tableManagers as $manager) {
            $status[$manager->getFeatureName()] = $manager->isInitialized($connection);
        }

        return $status;
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

