<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Gathering;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\Message;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupNotFound;
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
        private EcotoneClockInterface $clock,
    ) {
    }

    public function openGroup(string $groupId, string $gatherChannelName, int $size, Message $original, ?int $deadline, callable $whenOpened): void
    {
        $connection = $this->connection();
        $callbackFailure = null;
        try {
            $connection->transactional(function () use ($connection, $groupId, $gatherChannelName, $size, $original, $deadline, $whenOpened, &$callbackFailure): void {
                try {
                    $connection->transactional(fn () => $connection->insert(self::GROUP_TABLE, [
                        'group_id' => $groupId,
                        'gather_channel_name' => $gatherChannelName,
                        'size' => $size,
                        'arrived' => 0,
                        'version' => 0,
                        'state' => 'open',
                        'original_message' => $this->messages->encode($original),
                        'deadline' => $deadline,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    return;
                }
                try {
                    $whenOpened();
                } catch (Throwable $failure) {
                    $callbackFailure = $failure;
                    throw $failure;
                }
            });
        } catch (Throwable $failure) {
            throw $callbackFailure ?? MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function completePart(string $groupId, int $partNumber, Message $report, callable $whenComplete): bool
    {
        $connection = $this->connection();
        $callbackFailure = null;
        try {
            return $connection->transactional(function () use ($connection, $groupId, $partNumber, $report, $whenComplete, &$callbackFailure): bool {
                $group = $connection->fetchAssociative('SELECT * FROM ' . self::GROUP_TABLE . ' WHERE group_id = ?', [$groupId]);
                if (! $group) {
                    throw GatheredGroupNotFound::forId($groupId);
                }
                if ($group['state'] !== 'open') {
                    $this->noteLatePart($groupId);

                    return true;
                }
                $size = (int) $group['size'];
                if ($size > 0 && $connection->fetchOne('SELECT 1 FROM ' . self::PART_TABLE . ' WHERE group_id = ? AND part_number = ?', [$groupId, $partNumber]) !== false) {
                    return true;
                }
                $arrived = (int) $group['arrived'] + ($size > 0 ? 1 : 0);
                $completed = $arrived === $size;
                $version = (int) $group['version'];
                $updated = $connection->executeStatement(
                    'UPDATE ' . self::GROUP_TABLE . " SET arrived = ?, version = ?, state = ?, closed_at = ? WHERE group_id = ? AND version = ? AND state = 'open'",
                    [$arrived, $version + 1, $completed ? 'released' : 'open', $completed ? $this->clock->now()->unixTime()->inMilliseconds() : null, $groupId, $version],
                );
                if ($updated === 0) {
                    return false;
                }
                if ($size > 0) {
                    $connection->insert(self::PART_TABLE, [
                        'group_id' => $groupId,
                        'part_number' => $partNumber,
                        'report' => $this->messages->encode($report),
                    ]);
                }
                if ($completed) {
                    $parts = [];
                    foreach ($this->loadPartsForRelease($groupId) as $part) {
                        $parts[(int) $part['part_number']] = $this->messages->decode($part['report']);
                    }
                    try {
                        $whenComplete($this->messages->decode($group['original_message']), $parts, $size);
                    } catch (Throwable $failure) {
                        $callbackFailure = $failure;
                        throw $failure;
                    }
                }

                return true;
            });
        } catch (Throwable $failure) {
            throw $callbackFailure ?? MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function gatherChannelNameFor(string $groupId): string
    {
        try {
            $channel = $this->connection()->fetchOne('SELECT gather_channel_name FROM ' . self::GROUP_TABLE . ' WHERE group_id = ?', [$groupId]);
            if ($channel === false) {
                throw GatheredGroupNotFound::forId($groupId);
            }

            return $channel;
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function releaseGroup(string $groupId, callable $whenReleased): bool
    {
        $connection = $this->connection();
        $callbackFailure = null;
        try {
            return $connection->transactional(function () use ($connection, $groupId, $whenReleased, &$callbackFailure): bool {
                $group = $connection->fetchAssociative('SELECT * FROM ' . self::GROUP_TABLE . ' WHERE group_id = ?', [$groupId]);
                if (! $group || $group['state'] !== 'open') {
                    return true;
                }
                $version = (int) $group['version'];
                $released = $connection->executeStatement('UPDATE ' . self::GROUP_TABLE . " SET state = 'released', closed_at = ?, version = ? WHERE group_id = ? AND version = ? AND state = 'open'", [$this->clock->now()->unixTime()->inMilliseconds(), $version + 1, $groupId, $version]);
                if ($released === 0) {
                    return false;
                }
                $parts = [];
                foreach ($this->loadPartsForRelease($groupId) as $part) {
                    $parts[(int) $part['part_number']] = $this->messages->decode($part['report']);
                }
                try {
                    $whenReleased($this->messages->decode($group['original_message']), $parts, (int) $group['size']);
                } catch (Throwable $failure) {
                    $callbackFailure = $failure;
                    throw $failure;
                }

                return true;
            });
        } catch (Throwable $failure) {
            throw $callbackFailure ?? MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function discard(string $groupId): void
    {
        $connection = $this->connection();
        try {
            $connection->transactional(function () use ($connection, $groupId): void {
                $this->gatherChannelNameFor($groupId);
                $connection->executeStatement('UPDATE ' . self::GROUP_TABLE . " SET state = 'discarded', closed_at = ?, version = version + 1 WHERE group_id = ? AND state = 'open'", [$this->clock->now()->unixTime()->inMilliseconds(), $groupId]);
            });
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function cleanupClosedGroups(int $closedAtOrBefore): void
    {
        $connection = $this->connection();
        try {
            $connection->transactional(function () use ($connection, $closedAtOrBefore): void {
                $connection->executeStatement('DELETE FROM ' . self::PART_TABLE . ' WHERE group_id IN (SELECT group_id FROM ' . self::GROUP_TABLE . " WHERE state <> 'open' AND closed_at <= ?)", [$closedAtOrBefore]);
                $connection->executeStatement('DELETE FROM ' . self::GROUP_TABLE . " WHERE state <> 'open' AND closed_at <= ?", [$closedAtOrBefore]);
            });
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function listOpenGroups(string $gatherChannelName): array
    {
        return $this->listGroups($gatherChannelName, false);
    }

    public function listClosedGroups(string $gatherChannelName): array
    {
        return $this->listGroups($gatherChannelName, true);
    }

    private function listGroups(string $gatherChannelName, bool $closed): array
    {
        try {
            $rows = $this->connection()->fetchAllAssociative(
                'SELECT group_id, gather_channel_name, size, state, late_part_count, closed_at, deadline FROM ' . self::GROUP_TABLE . ' WHERE gather_channel_name = ? AND state ' . ($closed ? '<>' : '=') . " 'open'",
                [$gatherChannelName],
            );
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }

        return array_map(function (array $row) use ($closed): array {
            $accounted = array_map('intval', $this->connection()->fetchFirstColumn('SELECT part_number FROM ' . self::PART_TABLE . ' WHERE group_id = ? ORDER BY part_number', [$row['group_id']]));
            $size = (int) $row['size'];

            return ($closed ? ['state' => $row['state']] : []) + [
                'deadline' => $row['deadline'] === null ? null : (int) $row['deadline'],
                'closedAt' => $row['closed_at'] === null ? null : (int) $row['closed_at'],
                'latePartCount' => (int) $row['late_part_count'],
                'groupId' => $row['group_id'],
                'gatherChannelName' => $row['gather_channel_name'],
                'size' => $size,
                'accountedPartNumbers' => $accounted,
                'missingPartNumbers' => array_values(array_diff($size === 0 ? [] : range(1, $size), $accounted)),
            ];
        }, $rows);
    }

    private function noteLatePart(string $groupId): void
    {
        $this->connection()->executeStatement('UPDATE ' . self::GROUP_TABLE . " SET late_part_count = late_part_count + 1, version = version + 1 WHERE group_id = ? AND state <> 'open'", [$groupId]);
    }

    private function loadPartsForRelease(string $groupId): array
    {
        return $this->connection()->fetchAllAssociative('SELECT part_number, report FROM ' . self::PART_TABLE . ' WHERE group_id = ? ORDER BY part_number', [$groupId]);
    }

    private function connection(): Connection
    {
        $context = $this->connectionFactory->createContext();
        assert($context instanceof DbalContext);

        return $context->getDbalConnection();
    }
}
