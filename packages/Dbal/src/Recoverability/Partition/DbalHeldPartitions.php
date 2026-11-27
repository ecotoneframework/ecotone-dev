<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\Database\MissingColumnFailure;
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
    private const COLUMNS = ['partition_tracking_name', 'partition_key', 'partition_position'];

    public function __construct(
        private CachedConnectionFactory $connectionFactory,
        private DeadLetterTableManager $tableManager,
    ) {
    }

    public function partitionOf(string $deadLetterId): ?DeadLetterPartition
    {
        $held = $this->heldAs($deadLetterId);

        return $held ? new DeadLetterPartition(trackingName: (string) $held['partition_tracking_name'], partitionKey: (string) $held['partition_key']) : null;
    }

    public function positionOf(string $deadLetterId): ?int
    {
        $held = $this->heldAs($deadLetterId);

        return $held ? (int) $held['partition_position'] : null;
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
        $held = $this->onTable(fn () => $this->inOrder($this->connection()->createQueryBuilder()
            ->select('message_id', 'partition_tracking_name', 'partition_key')
            ->from($this->tableManager->getTableName())
            ->andWhere('partition_key IS NOT NULL'))
            ->executeQuery()
            ->fetchAllAssociative());
        foreach ($held as $row) {
            $partition = $row['partition_tracking_name'] . "\0" . $row['partition_key'];
            $summary[$partition] ??= ['trackingName' => (string) $row['partition_tracking_name'], 'partitionKey' => (string) $row['partition_key'], 'waiting' => 0, 'oldestDeadLetterId' => (string) $row['message_id']];
            $summary[$partition]['waiting']++;
        }

        return array_values($summary);
    }

    /**
     * @return array{partition_tracking_name: string, partition_key: string, partition_position: int|string}|false
     */
    private function heldAs(string $deadLetterId): array|false
    {
        return $this->onTable(fn () => $this->connection()->createQueryBuilder()
            ->select('partition_tracking_name', 'partition_key', 'partition_position')
            ->from($this->tableManager->getTableName())
            ->andWhere('message_id = :deadLetterId')
            ->andWhere('partition_key IS NOT NULL')
            ->setParameter('deadLetterId', $deadLetterId, Types::STRING)
            ->executeQuery()
            ->fetchAssociative());
    }

    private function deadLetterIdsOf(DeadLetterPartition $partition): QueryBuilder
    {
        return $this->inOrder($this->connection()->createQueryBuilder()
            ->select('message_id')
            ->from($this->tableManager->getTableName())
            ->andWhere('partition_tracking_name = :trackingName')
            ->andWhere('partition_key = :partitionKey')
            ->setParameter('trackingName', $partition->trackingName, Types::STRING)
            ->setParameter('partitionKey', $partition->partitionKey, Types::STRING));
    }

    private function inOrder(QueryBuilder $held): QueryBuilder
    {
        return $held->orderBy('partition_position', 'ASC')->addOrderBy('failed_at', 'ASC')->addOrderBy('message_id', 'ASC');
    }

    private function onTable(callable $statement): mixed
    {
        try {
            return $statement();
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained(
                MissingColumnFailure::ofColumns(failure: $failure, columns: self::COLUMNS, instructions: $this->missingColumnsInstructions()),
                $this->tableManager,
            );
        }
    }

    private function missingColumnsInstructions(): string
    {
        $table = $this->tableManager->getTableName();
        $index = $this->tableManager->getPartitionIndexName();

        return "The '{$table}' table has no 'partition_tracking_name', 'partition_key' and 'partition_position' columns, which holding partition keys in the dead letter (#[PartitionDeadLetter], PartitionDeadLetterConfiguration) writes and reads. The table was created before these columns were part of it. Ecotone never alters a table while it handles a message.\n\n"
            . "Add the columns with the statement for your database:\n"
            . "  PostgreSQL: ALTER TABLE {$table} ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN partition_position BIGINT DEFAULT NULL; CREATE INDEX {$index} ON {$table} (partition_tracking_name, partition_key, partition_position);\n"
            . "  MySQL and MariaDB: ALTER TABLE {$table} ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL, ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL, ADD COLUMN partition_position BIGINT DEFAULT NULL, ADD INDEX {$index} (partition_tracking_name, partition_key, partition_position);\n"
            . "  SQLite: ALTER TABLE {$table} ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN partition_position BIGINT DEFAULT NULL; CREATE INDEX {$index} ON {$table} (partition_tracking_name, partition_key, partition_position);\n\n"
            . 'Or stop holding partition keys: remove #[PartitionDeadLetter] and PartitionDeadLetterConfiguration.';
    }

    private function connection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        return $context->getDbalConnection();
    }
}
