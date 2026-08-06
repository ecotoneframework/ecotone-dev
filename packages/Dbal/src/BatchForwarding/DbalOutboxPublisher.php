<?php

declare(strict_types=1);

namespace Ecotone\Dbal\BatchForwarding;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\BatchSupportingMessageChannel;
use Ecotone\Messaging\Channel\MessageChannelInterceptorAdapter;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\DbalType;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Relays outbox rows without deserializing them: body stays an opaque payload in its stored content type,
 * headers are the already storeable scalars written by the producing side. Format conversion belongs to
 * the target channel when its media type differs, and to the final consumer when the handler is invoked.
 *
 * licence Enterprise
 */
final class DbalOutboxPublisher
{
    private const CLAIM_REDELIVERY_SAFETY_WINDOW_IN_SECONDS = 1200;

    public function __construct(
        private CachedConnectionFactory $connectionFactory,
        private string $queueName,
        private ChannelResolver $channelResolver,
        private LoggingGateway $logger,
        private EcotoneClockInterface $clock,
        private int $maxBatchSize,
        private FinalFailureStrategy $finalFailureStrategy,
    ) {
    }

    public function publishBatch(): int
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();
        $connection = $context->getDbalConnection();

        $connection->beginTransaction();
        try {
            $drainedRecords = $this->claimPendingRecords($context, $connection);
            if ($drainedRecords === []) {
                $this->markExpiredClaimsForRedelivery($context, $connection);
                $drainedRecords = $this->claimPendingRecords($context, $connection);
            }
            if ($drainedRecords === []) {
                $connection->commit();

                return 0;
            }

            $deliveredRowIds = [];
            $releasedRowIds = [];
            foreach ($this->groupByTargetChannel($drainedRecords, $releasedRowIds) as $groupTargetChannelName => $groupedRecords) {
                $this->forwardGroup((string) $groupTargetChannelName, $groupedRecords, $deliveredRowIds, $releasedRowIds);
            }

            $this->deleteRows($context, $connection, $deliveredRowIds);
            $this->releaseRows($context, $connection, $releasedRowIds);
            $connection->commit();

            return count($deliveredRowIds);
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    private function markExpiredClaimsForRedelivery(DbalContext $context, Connection $connection): void
    {
        $connection->createQueryBuilder()
            ->update($context->getTableName())
            ->set('delivery_id', ':deliveryId')
            ->set('redelivered', ':redelivered')
            ->andWhere('queue = :queue')
            ->andWhere('redeliver_after < :now')
            ->andWhere('delivery_id IS NOT NULL')
            ->setParameter('queue', $this->queueName)
            ->setParameter('now', $this->clock->now()->unixTime()->inSeconds(), DbalType::BIGINT)
            ->setParameter('deliveryId', null, DbalType::GUID)
            ->setParameter('redelivered', true, DbalType::BOOLEAN)
            ->executeStatement();
    }

    /**
     * @return array<int, array{rowId: string, targetChannelName: string|null, payload: string, headers: array<string, mixed>}>
     */
    private function claimPendingRecords(DbalContext $context, Connection $connection): array
    {
        $nowInSeconds = $this->clock->now()->unixTime()->inSeconds();

        $claimedRows = $this->supportsLockedFetch($connection)
            ? $this->fetchPendingRowsWithLock($context, $connection, $nowInSeconds)
            : $this->fetchPendingRowsWithClaimMarker($context, $connection, $nowInSeconds);
        if ($claimedRows === []) {
            return [];
        }

        $expiredRowIds = [];
        $records = [];
        foreach ($claimedRows as $claimedRow) {
            if (! ($claimedRow['redelivered'] || empty($claimedRow['time_to_live']) || $claimedRow['time_to_live'] > $nowInSeconds)) {
                $expiredRowIds[] = $claimedRow['id'];

                continue;
            }

            $records[] = $this->convertRowToForwardableRecord($claimedRow);
        }
        $this->deleteRows($context, $connection, $expiredRowIds);

        return $records;
    }

    /**
     * @param array<string, mixed> $claimedRow
     * @return array{rowId: string, targetChannelName: string|null, payload: string, headers: array<string, mixed>}
     */
    private function convertRowToForwardableRecord(array $claimedRow): array
    {
        $headers = $claimedRow['properties'] ? json_decode((string) $claimedRow['properties'], true, 512, JSON_THROW_ON_ERROR) : [];
        unset($headers[MessageHeaders::POLLED_CHANNEL_NAME], $headers[MessageHeaders::CONSUMER_POLLING_METADATA], $headers[MessageHeaders::CONSUMER_ACK_HEADER_LOCATION]);

        $routingSlipChannels = array_filter(explode(',', (string) ($headers[MessageHeaders::ROUTING_SLIP] ?? '')));
        $targetChannelName = array_shift($routingSlipChannels);
        if ($routingSlipChannels === []) {
            unset($headers[MessageHeaders::ROUTING_SLIP]);
        } else {
            $headers[MessageHeaders::ROUTING_SLIP] = implode(',', $routingSlipChannels);
        }

        return [
            'rowId' => $claimedRow['id'],
            'targetChannelName' => $targetChannelName,
            'payload' => (string) $claimedRow['body'],
            'headers' => $headers,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPendingRowsWithLock(DbalContext $context, Connection $connection, int $nowInSeconds): array
    {
        $lockedFetchSql = sprintf(
            'SELECT * FROM %s WHERE queue = :queue AND (delayed_until IS NULL OR delayed_until <= :now) AND delivery_id IS NULL ORDER BY priority ASC, published_at ASC LIMIT %d FOR UPDATE SKIP LOCKED',
            $context->getTableName(),
            $this->maxBatchSize,
        );

        return $connection->executeQuery(
            $lockedFetchSql,
            ['queue' => $this->queueName, 'now' => $nowInSeconds],
            ['now' => DbalType::INTEGER],
        )->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPendingRowsWithClaimMarker(DbalContext $context, Connection $connection, int $nowInSeconds): array
    {
        $selectedRows = $connection->createQueryBuilder()
            ->select('*')
            ->from($context->getTableName())
            ->andWhere('queue = :queue')
            ->andWhere('delayed_until IS NULL OR delayed_until <= :now')
            ->andWhere('delivery_id IS NULL')
            ->addOrderBy('priority', 'asc')
            ->addOrderBy('published_at', 'asc')
            ->setMaxResults($this->maxBatchSize)
            ->setParameter('queue', $this->queueName)
            ->setParameter('now', $nowInSeconds, DbalType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();
        if ($selectedRows === []) {
            return [];
        }

        $batchDeliveryId = Uuid::v7()->toRfc4122();
        $claimedAmount = $connection->createQueryBuilder()
            ->update($context->getTableName())
            ->set('delivery_id', ':deliveryId')
            ->set('redeliver_after', ':redeliverAfter')
            ->andWhere('id IN (:rowIds)')
            ->andWhere('delivery_id IS NULL')
            ->setParameter('deliveryId', $batchDeliveryId, DbalType::GUID)
            ->setParameter('redeliverAfter', $nowInSeconds + self::CLAIM_REDELIVERY_SAFETY_WINDOW_IN_SECONDS, DbalType::BIGINT)
            ->setParameter('rowIds', array_column($selectedRows, 'id'), $this->arrayOfStringsParameterType())
            ->executeStatement();
        if ($claimedAmount === 0) {
            return [];
        }
        if ($claimedAmount === count($selectedRows)) {
            return $selectedRows;
        }

        return $connection->createQueryBuilder()
            ->select('*')
            ->from($context->getTableName())
            ->andWhere('delivery_id = :deliveryId')
            ->addOrderBy('priority', 'asc')
            ->addOrderBy('published_at', 'asc')
            ->setParameter('deliveryId', $batchDeliveryId, DbalType::GUID)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function supportsLockedFetch(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    /**
     * @param array<int, array{rowId: string, targetChannelName: string|null, payload: string, headers: array<string, mixed>}> $drainedRecords
     * @param string[] $releasedRowIds
     * @return array<string, array<int, array{rowId: string, targetChannelName: string|null, payload: string, headers: array<string, mixed>}>>
     */
    private function groupByTargetChannel(array $drainedRecords, array &$releasedRowIds): array
    {
        $groups = [];
        foreach ($drainedRecords as $drainedRecord) {
            $targetChannelName = $drainedRecord['targetChannelName'];
            if ($targetChannelName === null) {
                $releasedRowIds[] = $drainedRecord['rowId'];
                $this->logger->error(
                    sprintf('Message with id `%s` inside outbox Channel `%s` has no routing slip to determine the forwarding target. It was released for redelivery.', $drainedRecord['headers'][MessageHeaders::MESSAGE_ID] ?? 'unknown', $this->queueName),
                );

                continue;
            }
            $groups[$targetChannelName][] = $drainedRecord;
        }

        return $groups;
    }

    /**
     * @param array<int, array{rowId: string, targetChannelName: string|null, payload: string, headers: array<string, mixed>}> $groupedRecords
     * @param string[] $deliveredRowIds
     * @param string[] $releasedRowIds
     */
    private function forwardGroup(string $targetChannelName, array $groupedRecords, array &$deliveredRowIds, array &$releasedRowIds): void
    {
        $targetChannel = $this->channelResolver->resolve($targetChannelName);

        if ($this->supportsBatchMessages($targetChannel)) {
            try {
                $targetChannel->send(
                    MessageBuilder::withPayload(
                        BatchMessage::fromEntries(array_map(
                            fn (array $groupedRecord) => ['payload' => $groupedRecord['payload'], 'headers' => $groupedRecord['headers']],
                            $groupedRecords,
                        )),
                    )->build(),
                );
            } catch (Throwable $exception) {
                $this->handleFailedDelivery($groupedRecords, $targetChannelName, $exception, $deliveredRowIds, $releasedRowIds);

                return;
            }
            foreach ($groupedRecords as $groupedRecord) {
                $deliveredRowIds[] = $groupedRecord['rowId'];
            }

            return;
        }

        foreach ($groupedRecords as $groupedRecord) {
            try {
                $targetChannel->send(
                    MessageBuilder::withPayload($groupedRecord['payload'])
                        ->setMultipleHeaders($groupedRecord['headers'])
                        ->build(),
                );
            } catch (Throwable $exception) {
                $this->handleFailedDelivery([$groupedRecord], $targetChannelName, $exception, $deliveredRowIds, $releasedRowIds);

                continue;
            }
            $deliveredRowIds[] = $groupedRecord['rowId'];
        }
    }

    /**
     * @param array<int, array{rowId: string, targetChannelName: string|null, payload: string, headers: array<string, mixed>}> $failedRecords
     * @param string[] $deliveredRowIds
     * @param string[] $releasedRowIds
     */
    private function handleFailedDelivery(array $failedRecords, string $targetChannelName, Throwable $exception, array &$deliveredRowIds, array &$releasedRowIds): void
    {
        if ($exception instanceof ConnectionException || $this->finalFailureStrategy === FinalFailureStrategy::STOP) {
            throw $exception;
        }

        foreach ($failedRecords as $failedRecord) {
            if ($this->finalFailureStrategy === FinalFailureStrategy::IGNORE) {
                $deliveredRowIds[] = $failedRecord['rowId'];
            } else {
                $releasedRowIds[] = $failedRecord['rowId'];
            }
            $this->logger->info(
                sprintf('Message with id `%s` handled with `%s` failure strategy, as delivery to `%s` failed. Due to %s', $failedRecord['headers'][MessageHeaders::MESSAGE_ID] ?? 'unknown', $this->finalFailureStrategy->value, $targetChannelName, $exception->getMessage()),
                ['exception' => $exception],
            );
        }
    }

    /**
     * @param string[] $rowIds
     */
    private function deleteRows(DbalContext $context, Connection $connection, array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $connection->createQueryBuilder()
            ->delete($context->getTableName())
            ->andWhere('id IN (:rowIds)')
            ->setParameter('rowIds', $rowIds, $this->arrayOfStringsParameterType())
            ->executeStatement();
    }

    /**
     * @param string[] $rowIds
     */
    private function releaseRows(DbalContext $context, Connection $connection, array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $connection->createQueryBuilder()
            ->update($context->getTableName())
            ->set('delivery_id', ':deliveryId')
            ->set('redelivered', ':redelivered')
            ->andWhere('id IN (:rowIds)')
            ->setParameter('deliveryId', null, DbalType::GUID)
            ->setParameter('redelivered', true, DbalType::BOOLEAN)
            ->setParameter('rowIds', $rowIds, $this->arrayOfStringsParameterType())
            ->executeStatement();
    }

    private function supportsBatchMessages(MessageChannel $channel): bool
    {
        $unwrappedChannel = $channel instanceof MessageChannelInterceptorAdapter ? $channel->getInternalMessageChannel() : $channel;

        return $unwrappedChannel instanceof BatchSupportingMessageChannel && $unwrappedChannel->supportsBatchMessages();
    }

    private function arrayOfStringsParameterType(): mixed
    {
        return class_exists('\Doctrine\DBAL\ArrayParameterType')
            ? \Doctrine\DBAL\ArrayParameterType::STRING
            : (defined('\Doctrine\DBAL\Connection::PARAM_STR_ARRAY') ? Connection::PARAM_STR_ARRAY : 'string[]');
    }
}
