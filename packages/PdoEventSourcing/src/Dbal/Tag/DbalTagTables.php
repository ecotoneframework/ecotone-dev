<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Dbal\Database\AutomaticTableInitializationSupport;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Enterprise
 */
final class DbalTagTables
{
    /** @var array<string, bool> */
    private array $ensuredTables = [];

    public function ensureExist(DbalEventStore $eventStore, Connection $connection, string $streamName): void
    {
        $contextKey = $eventStore->tagTableContextKeyFor($streamName);
        if (isset($this->ensuredTables[$contextKey])) {
            return;
        }

        $tableManager = $this->tableManagerFor($eventStore);
        if (! $tableManager->isInitialized($connection)) {
            $this->assertCanBeCreatedAutomatically($eventStore, $connection);
            $tableManager->createTable($connection);
        }

        $this->ensuredTables[$contextKey] = true;
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function raisingMissingTablesInstruction(DbalEventStore $eventStore, Connection $connection, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (TableNotFoundException) {
            throw $this->missingTablesException($eventStore, $connection);
        }
    }

    public function missingTablesException(DbalEventStore $eventStore, Connection $connection): ConfigurationException
    {
        return ConfigurationException::create($this->tableManagerFor($eventStore)->getMissingTableInstructions($connection));
    }

    private function assertCanBeCreatedAutomatically(DbalEventStore $eventStore, Connection $connection): void
    {
        if ($eventStore->isAutomaticTableInitializationEnabled() && AutomaticTableInitializationSupport::isSupported($connection)) {
            return;
        }

        throw $this->missingTablesException($eventStore, $connection);
    }

    private function tableManagerFor(DbalEventStore $eventStore): TagTableManager
    {
        return new TagTableManager(true, $eventStore->isAutomaticTableInitializationEnabled(), $eventStore->consoleInvocationPrefix());
    }
}
