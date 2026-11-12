<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Gathering;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStore;
use Throwable;

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
 *
 */
final class DbalGatheredGroupStore implements GatheredGroupStore
{
    public const GROUP_TABLE = 'ecotone_gathering_groups';

    public function __construct(
        private CachedConnectionFactory $connectionFactory,
        private GatheringGroupTableManager $tableManager,
    ) {
    }

    public function openGroup(string $groupId, string $gatherChannelName, int $size): void
    {
        $connection = $this->connection();
        $onDuplicate = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' ON DUPLICATE KEY UPDATE group_id = group_id'
            : ' ON CONFLICT (group_id) DO NOTHING';
        try {
            $connection->executeStatement(
                'INSERT INTO ' . self::GROUP_TABLE . ' (group_id, gather_channel_name, size) VALUES (?, ?, ?)' . $onDuplicate,
                [$groupId, $gatherChannelName, $size],
            );
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function listOpenGroups(string $gatherChannelName): array
    {
        try {
            $rows = $this->connection()->fetchAllAssociative(
                'SELECT group_id, gather_channel_name, size FROM ' . self::GROUP_TABLE . ' WHERE gather_channel_name = ?',
                [$gatherChannelName],
            );
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }

        return array_map(static fn (array $row): array => [
            'groupId' => $row['group_id'],
            'gatherChannelName' => $row['gather_channel_name'],
            'size' => (int) $row['size'],
            'accountedPartNumbers' => [],
            'missingPartNumbers' => range(1, (int) $row['size']),
        ], $rows);
    }

    private function connection(): Connection
    {
        $context = $this->connectionFactory->createContext();
        assert($context instanceof DbalContext);

        return $context->getDbalConnection();
    }
}
