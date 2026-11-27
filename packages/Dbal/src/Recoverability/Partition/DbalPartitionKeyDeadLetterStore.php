<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\Messaging\Message;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\Recoverability\DbalDeadLetterStore;
use Ecotone\Enqueue\CachedConnectionFactory;

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
final class DbalPartitionKeyDeadLetterStore implements PartitionKeyDeadLetterStore
{
    private const PARTITION_TYPES = [
        'partition_tracking_name' => Types::STRING,
        'partition_key' => Types::STRING,
        'partition_position' => Types::BIGINT,
    ];

    public function __construct(
        private DbalDeadLetterStore $deadLetterStore,
        private CachedConnectionFactory $connectionFactory,
        private DeadLetterTableManager $tableManager,
    ) {
    }

    public function storeUnderPartitionKey(Message $message, DeadLetterPartition $partition, ?int $position): string
    {
        return $this->deadLetterStore->storeInsertedBy($message, fn (array $row, array $types): int|string => $position === null
            ? $this->insertAfterTheLastOf(partition: $partition, row: $row, types: $types)
            : $this->insertAtPositionOf(partition: $partition, position: $position, row: $row, types: $types));
    }

    public function store(Message $message): string
    {
        return $this->deadLetterStore->store($message);
    }

    public function list(int $limit, int $offset): array
    {
        return $this->deadLetterStore->list($limit, $offset);
    }

    public function listMessages(int $limit, int $offset): array
    {
        return $this->deadLetterStore->listMessages($limit, $offset);
    }

    public function find(string $messageId): ?Message
    {
        return $this->deadLetterStore->find($messageId);
    }

    public function count(): int
    {
        return $this->deadLetterStore->count();
    }

    public function delete(string $messageId): void
    {
        $this->deadLetterStore->delete($messageId);
    }

    public function deleteAll(): void
    {
        $this->deadLetterStore->deleteAll();
    }

    private function insertAfterTheLastOf(DeadLetterPartition $partition, array $row, array $types): int|string
    {
        $table = $this->tableManager->getTableName();
        $columns = array_keys($row);

        return $this->connection()->executeStatement(
            sql: "INSERT INTO {$table} (" . implode(', ', $columns) . ', partition_tracking_name, partition_key, partition_position) '
            . 'SELECT :' . implode(', :', $columns) . ', :partition_tracking_name, :partition_key, COALESCE(MAX(partition_position), 0) + 1 '
            . "FROM {$table} WHERE partition_tracking_name = :partition_tracking_name AND partition_key = :partition_key",
            params: $row + ['partition_tracking_name' => $partition->trackingName, 'partition_key' => $partition->partitionKey],
            types: $types + self::PARTITION_TYPES,
        );
    }

    private function insertAtPositionOf(DeadLetterPartition $partition, int $position, array $row, array $types): int|string
    {
        $table = $this->tableManager->getTableName();
        $held = ['partition_tracking_name' => $partition->trackingName, 'partition_key' => $partition->partitionKey, 'partition_position' => $position];
        $isPositionTaken = $this->connection()->fetchOne(
            query: "SELECT 1 FROM {$table} WHERE partition_tracking_name = :partition_tracking_name AND partition_key = :partition_key AND partition_position = :partition_position",
            params: $held,
            types: self::PARTITION_TYPES,
        ) !== false;
        if ($isPositionTaken) {
            $this->connection()->executeStatement(
                sql: "UPDATE {$table} SET partition_position = partition_position + 1 WHERE partition_tracking_name = :partition_tracking_name AND partition_key = :partition_key AND partition_position >= :partition_position",
                params: $held,
                types: self::PARTITION_TYPES,
            );
        }

        return $this->connection()->insert(table: $table, data: $row + $held, types: $types + self::PARTITION_TYPES);
    }

    private function connection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        return $context->getDbalConnection();
    }
}
