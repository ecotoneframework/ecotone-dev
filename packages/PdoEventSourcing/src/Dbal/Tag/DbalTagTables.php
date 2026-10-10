<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Closure;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Enterprise
 */
final class DbalTagTables
{
    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function raisingMissingTablesInstruction(DbalEventStore $eventStore, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (TableNotFoundException) {
            throw $this->missingTablesException($eventStore);
        }
    }

    public function missingTablesException(DbalEventStore $eventStore): ConfigurationException
    {
        return ConfigurationException::create($this->tableManagerFor($eventStore)->getMissingTableInstructions());
    }

    private function tableManagerFor(DbalEventStore $eventStore): TagTableManager
    {
        return new TagTableManager(true, $eventStore->consoleInvocationPrefix());
    }
}
