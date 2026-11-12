<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Gathering;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\Messaging\Message;
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
    public const PART_TABLE = 'ecotone_gathering_parts';

    public function __construct(
        private CachedConnectionFactory $connectionFactory,
        private GatheringTableManager $tableManager,
        private StoredGatheringMessages $messages,
    ) {
    }

    public function openGroup(string $groupId, string $gatherChannelName, int $size, Message $original): void
    {
        $connection = $this->connection();
        $onDuplicate = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' ON DUPLICATE KEY UPDATE group_id = group_id'
            : ' ON CONFLICT (group_id) DO NOTHING';
        try {
            $connection->executeStatement(
                'INSERT INTO ' . self::GROUP_TABLE . ' (group_id, gather_channel_name, size, arrived, state, original_message) VALUES (?, ?, ?, 0, ?, ?)' . $onDuplicate,
                [$groupId, $gatherChannelName, $size, 'open', $this->messages->encode($original)],
            );
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function completePart(string $groupId, int $partNumber, Message $report, callable $whenComplete): void
    {
        $connection = $this->connection();
        try {
            $connection->transactional(function () use ($connection, $groupId, $partNumber, $report, $whenComplete): void {
                $group = $connection->fetchAssociative('SELECT * FROM ' . self::GROUP_TABLE . ' WHERE group_id = ?', [$groupId]);
                if (! $group || $group['state'] !== 'open') {
                    return;
                }
                if ((int) $group['size'] > 0) {
                    try {
                        $connection->transactional(fn () => $connection->insert(self::PART_TABLE, [
                            'group_id' => $groupId,
                            'part_number' => $partNumber,
                            'report' => $this->messages->encode($report),
                        ]));
                    } catch (UniqueConstraintViolationException) {
                        return;
                    }
                    $updated = $connection->executeStatement('UPDATE ' . self::GROUP_TABLE . " SET arrived = arrived + 1 WHERE group_id = ? AND state = 'open'", [$groupId]);
                    if ($updated === 0) {
                        return;
                    }
                    $group = $connection->fetchAssociative('SELECT * FROM ' . self::GROUP_TABLE . ' WHERE group_id = ?', [$groupId]);
                    if ((int) $group['arrived'] !== (int) $group['size']) {
                        return;
                    }
                }
                $released = $connection->executeStatement('UPDATE ' . self::GROUP_TABLE . " SET state = 'released' WHERE group_id = ? AND state = 'open'", [$groupId]);
                if ($released === 0) {
                    return;
                }
                $parts = [];
                foreach ($this->loadPartsForRelease($groupId) as $part) {
                    $parts[(int) $part['part_number']] = $this->messages->decode($part['report']);
                }
                $whenComplete($this->messages->decode($group['original_message']), $parts, (int) $group['size']);
            });
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function listOpenGroups(string $gatherChannelName): array
    {
        try {
            $rows = $this->connection()->fetchAllAssociative(
                'SELECT group_id, gather_channel_name, size FROM ' . self::GROUP_TABLE . " WHERE gather_channel_name = ? AND state = 'open'",
                [$gatherChannelName],
            );
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }

        return array_map(function (array $row): array {
            $accounted = array_map('intval', $this->connection()->fetchFirstColumn('SELECT part_number FROM ' . self::PART_TABLE . ' WHERE group_id = ? ORDER BY part_number', [$row['group_id']]));
            $size = (int) $row['size'];

            return [
                'groupId' => $row['group_id'],
                'gatherChannelName' => $row['gather_channel_name'],
                'size' => $size,
                'accountedPartNumbers' => $accounted,
                'missingPartNumbers' => array_values(array_diff($size === 0 ? [] : range(1, $size), $accounted)),
            ];
        }, $rows);
    }

    private function loadPartsForRelease(string $groupId): array
    {
        $platform = $this->connection()->getDatabasePlatform();
        $currentRead = match (true) {
            $platform instanceof AbstractMySQLPlatform => ' LOCK IN SHARE MODE',
            $platform instanceof PostgreSQLPlatform => ' FOR SHARE',
            default => '',
        };

        return $this->connection()->fetchAllAssociative('SELECT part_number, report FROM ' . self::PART_TABLE . ' WHERE group_id = ? ORDER BY part_number' . $currentRead, [$groupId]);
    }

    private function connection(): Connection
    {
        $context = $this->connectionFactory->createContext();
        assert($context instanceof DbalContext);

        return $context->getDbalConnection();
    }
}
