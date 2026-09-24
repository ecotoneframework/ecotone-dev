<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Dbal\Database\DdlOutsideActiveTransaction;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
final class EventStreamTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'event_stream';

    /**
     * @param array<string> $tableNames
     */
    public function __construct(
        private array $tableNames,
        private bool  $isUsed,
        private bool  $shouldAutoInitialize,
        private ?string $consoleInvocationPrefix = null,
    ) {
    }

    public function getFeatureName(): string
    {
        return self::FEATURE_NAME;
    }

    public function isUsed(): bool
    {
        return $this->isUsed;
    }

    /**
     * @return array<string>
     */
    public function getTableNames(): array
    {
        return $this->tableNames;
    }

    public function getCreateTableSql(Connection $connection): string|array
    {
        $schema = EventStreamSchemaFactory::for($connection);
        $statements = [];
        foreach ($this->tableNames as $tableName) {
            foreach ($schema->createTableSql($tableName) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    public function getDropTableSql(Connection $connection): string
    {
        $schema = EventStreamSchemaFactory::for($connection);

        return implode(";\n", array_map(
            fn (string $tableName) => $schema->dropTableSql($tableName),
            $this->tableNames
        ));
    }

    public function createTable(Connection $connection): void
    {
        DdlOutsideActiveTransaction::execute($connection, $this->getCreateTableSql($connection));
    }

    public function dropTable(Connection $connection): void
    {
        $schema = EventStreamSchemaFactory::for($connection);
        DdlOutsideActiveTransaction::execute($connection, array_map(
            fn (string $tableName) => $schema->dropTableSql($tableName),
            $this->tableNames
        ));
    }

    public function isInitialized(Connection $connection): bool
    {
        $schema = EventStreamSchemaFactory::for($connection);
        foreach ($this->tableNames as $tableName) {
            if (! $schema->tableExists($connection, $tableName)) {
                return false;
            }
        }

        return true;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableNames, $this->isUsed, $this->shouldAutoInitialize, $this->consoleInvocationPrefix]);
    }

    public function shouldBeInitializedAutomatically(): bool
    {
        return $this->shouldAutoInitialize;
    }

    public function getMissingTableInstructions(): string
    {
        return MissingTableInstructions::build(self::FEATURE_NAME, implode(', ', $this->tableNames), $this->consoleInvocationPrefix);
    }
}
