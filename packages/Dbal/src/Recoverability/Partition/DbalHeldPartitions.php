<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\DeadLetterPartitionsTableManager;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Enqueue\CachedConnectionFactory;
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
 */
final class DbalHeldPartitions implements HeldPartitions
{
    private const PLACE = 'COALESCE(kept_place, position)';

    public function __construct(
        private CachedConnectionFactory $connectionFactory,
        private DeadLetterPartitionsTableManager $tableManager,
    ) {
    }

    public function hold(DeadLetterPartition $partition, string $deadLetterId): void
    {
        $this->onTable(fn () => $this->connection()->insert(
            $this->tableManager->getTableName(),
            ['dead_letter_id' => $deadLetterId, 'tracking_name' => $partition->trackingName, 'partition_key' => $partition->partitionKey],
            ['dead_letter_id' => Types::STRING, 'tracking_name' => Types::STRING, 'partition_key' => Types::STRING],
        ));
    }

    public function holdAtPlace(DeadLetterPartition $partition, string $deadLetterId, int $place): void
    {
        $this->onTable(fn () => $this->connection()->insert(
            $this->tableManager->getTableName(),
            ['dead_letter_id' => $deadLetterId, 'tracking_name' => $partition->trackingName, 'partition_key' => $partition->partitionKey, 'kept_place' => $place],
            ['dead_letter_id' => Types::STRING, 'tracking_name' => Types::STRING, 'partition_key' => Types::STRING, 'kept_place' => Types::BIGINT],
        ));
    }

    public function forget(string $deadLetterId): void
    {
        $this->onTable(fn () => $this->connection()->delete(
            $this->tableManager->getTableName(),
            ['dead_letter_id' => $deadLetterId],
            ['dead_letter_id' => Types::STRING],
        ));
    }

    public function forgetAll(): void
    {
        $this->onTable(fn () => $this->connection()->createQueryBuilder()->delete($this->tableManager->getTableName())->executeStatement());
    }

    public function partitionOf(string $deadLetterId): ?DeadLetterPartition
    {
        $held = $this->heldAs($deadLetterId);

        return $held ? new DeadLetterPartition(trackingName: (string) $held['tracking_name'], partitionKey: (string) $held['partition_key']) : null;
    }

    public function placeOf(string $deadLetterId): ?int
    {
        $held = $this->heldAs($deadLetterId);

        return $held ? (int) $held['place'] : null;
    }

    public function oldestOf(DeadLetterPartition $partition): ?string
    {
        $deadLetterId = $this->onTable(fn () => $this->deadLetterIdsOf($partition)->setMaxResults(1)->executeQuery()->fetchOne());

        return $deadLetterId === false ? null : (string) $deadLetterId;
    }

    public function waitingOf(DeadLetterPartition $partition): array
    {
        return array_map('strval', $this->onTable(fn () => $this->deadLetterIdsOf($partition)->executeQuery()->fetchFirstColumn()));
    }

    public function summary(): array
    {
        $summary = [];
        $held = $this->onTable(fn () => $this->connection()->createQueryBuilder()
            ->select('dead_letter_id', 'tracking_name', 'partition_key')
            ->from($this->tableManager->getTableName())
            ->orderBy(self::PLACE, 'ASC')
            ->addOrderBy('position', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative());
        foreach ($held as $row) {
            $partition = $row['tracking_name'] . "\0" . $row['partition_key'];
            $summary[$partition] ??= ['trackingName' => (string) $row['tracking_name'], 'partitionKey' => (string) $row['partition_key'], 'waiting' => 0, 'oldestDeadLetterId' => (string) $row['dead_letter_id']];
            $summary[$partition]['waiting']++;
        }

        return array_values($summary);
    }

    /**
     * @return array{tracking_name: string, partition_key: string, place: int|string}|false
     */
    private function heldAs(string $deadLetterId): array|false
    {
        return $this->onTable(fn () => $this->connection()->createQueryBuilder()
            ->select('tracking_name', 'partition_key', self::PLACE . ' AS place')
            ->from($this->tableManager->getTableName())
            ->andWhere('dead_letter_id = :deadLetterId')
            ->setParameter('deadLetterId', $deadLetterId, Types::STRING)
            ->executeQuery()
            ->fetchAssociative());
    }

    private function deadLetterIdsOf(DeadLetterPartition $partition): QueryBuilder
    {
        return $this->connection()->createQueryBuilder()
            ->select('dead_letter_id')
            ->from($this->tableManager->getTableName())
            ->andWhere('tracking_name = :trackingName')
            ->andWhere('partition_key = :partitionKey')
            ->setParameter('trackingName', $partition->trackingName, Types::STRING)
            ->setParameter('partitionKey', $partition->partitionKey, Types::STRING)
            ->orderBy(self::PLACE, 'ASC')
            ->addOrderBy('position', 'ASC');
    }

    private function onTable(callable $statement): mixed
    {
        try {
            return $statement();
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    private function connection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        return $context->getDbalConnection();
    }
}
