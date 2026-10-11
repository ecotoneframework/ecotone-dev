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
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
