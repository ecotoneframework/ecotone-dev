<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ManagerRegistryConnectionFactory;
use Ecotone\Dbal\AlreadyConnectedDbalConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\Tag\DbalTagIndex;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Projecting\PartitionProvider;
use Ecotone\Projecting\StreamFilter;

use function in_array;

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
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class DbalTagPartitionProvider implements PartitionProvider
{
    /**
     * @param string[] $tagPartitionedProjectionNames
     */
    public function __construct(
        private DbalConnectionFactory|ManagerRegistryConnectionFactory|MultiTenantConnectionFactory|AlreadyConnectedDbalConnectionFactory $connectionFactory,
        private DbalTagIndex $tagIndex,
        private TagTableManager $tagTableManager,
        private array $tagPartitionedProjectionNames,
    ) {
    }

    public function canHandle(string $projectionName): bool
    {
        return in_array($projectionName, $this->tagPartitionedProjectionNames, true);
    }

    public function count(StreamFilter $filter): int
    {
        try {
            return $this->tagIndex->countValuesOfTag($this->getConnection(), $filter->tag);
        } catch (TableNotFoundException) {
            throw ConfigurationException::create($this->tagTableManager->getMissingTableInstructions());
        }
    }

    public function partitions(StreamFilter $filter, ?int $limit = null, int $offset = 0): iterable
    {
        try {
            return $this->tagIndex->valuesOfTag($this->getConnection(), $filter->tag, $limit, $offset);
        } catch (TableNotFoundException) {
            throw ConfigurationException::create($this->tagTableManager->getMissingTableInstructions());
        }
    }

    private function getConnection(): Connection
    {
        if ($this->connectionFactory instanceof MultiTenantConnectionFactory || $this->connectionFactory instanceof AlreadyConnectedDbalConnectionFactory) {
            return $this->connectionFactory->getConnection();
        }

        return $this->connectionFactory->establishConnection();
    }
}
