<?php

declare(strict_types=1);

namespace Enqueue\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\Duration;
use LogicException;
use Ramsey\Uuid\Uuid;

/**
 * licence MIT
 * code comes from https://github.com/php-enqueue/dbal
 */
trait DbalConsumerHelperTrait
{
    private ?DatePoint $redeliverMessagesLastExecutedAt = null;

    private ?DatePoint $removeExpiredMessagesLastExecutedAt = null;

    abstract protected function getContext(): DbalContext;

    abstract protected function getConnection(): Connection;

    abstract protected function now(): DatePoint;

    protected function fetchMessage(array $queues, int $redeliveryDelay): ?DbalMessage
    {
        if (empty($queues)) {
            throw new LogicException('Queues must not be empty.');
        }

        $now = $this->now();
        $deliveryId = Uuid::uuid4();
        $redeliveryDelayDuration = Duration::seconds($redeliveryDelay);

        $endAt = $now->add(Duration::milliseconds(200));

        $select = $this->getConnection()->createQueryBuilder()
            ->select('id')
            ->from($this->getContext()->getTableName())
            ->andWhere('queue IN (:queues)')
            ->andWhere('delayed_until IS NULL OR delayed_until <= :delayedUntil')
            ->andWhere('delivery_id IS NULL')
            ->addOrderBy('priority', 'asc')
            ->addOrderBy('published_at', 'asc')
            ->setParameter('queues', $queues, class_exists('\Doctrine\DBAL\ArrayParameterType') ? \Doctrine\DBAL\ArrayParameterType::STRING : (defined('\Doctrine\DBAL\Connection::PARAM_STR_ARRAY') ? Connection::PARAM_STR_ARRAY : 'string[]'))
            ->setParameter('delayedUntil', $now->unixTime()->inSeconds(), DbalType::INTEGER)
            ->setMaxResults(1);

        $update = $this->getConnection()->createQueryBuilder()
            ->update($this->getContext()->getTableName())
            ->set('delivery_id', ':deliveryId')
            ->set('redeliver_after', ':redeliverAfter')
            ->andWhere('id = :messageId')
            ->andWhere('delivery_id IS NULL')
            ->setParameter('deliveryId', $deliveryId, DbalType::GUID)
            ->setParameter('redeliverAfter', $now->add($redeliveryDelayDuration)->unixTime()->inSeconds(), DbalType::BIGINT)
        ;

        while ($this->now() < $endAt) {
            try {
                $result = method_exists($select, 'execute') ?
                    $select->execute()->fetch() :
                    $select->executeQuery()->fetchAssociative();
                if (empty($result)) {
                    return null;
                }

                $update
                    ->setParameter('messageId', $result['id'], DbalType::GUID);

                // In DBAL 4.x, execute() is replaced with executeStatement()
                $executeResult = method_exists($update, 'execute') ?
                    $update->execute() :
                    $update->executeStatement();
                if ($executeResult) {
                    $deliveredMessage = $this->getConnection()->createQueryBuilder()
                        ->select('*')
                        ->from($this->getContext()->getTableName())
                        ->andWhere('delivery_id = :deliveryId')
                        ->setParameter('deliveryId', $deliveryId, DbalType::GUID)
                        ->setMaxResults(1)
                        ->executeQuery()
                        ->fetchAssociative();

                    // the message has been removed by a 3rd party, such as truncate operation.
                    if (false === $deliveredMessage) {
                        continue;
                    }

                    if ($deliveredMessage['redelivered'] || empty($deliveredMessage['time_to_live']) || $deliveredMessage['time_to_live'] > $this->now()->unixTime()->inSeconds()) {
                        return $this->getContext()->convertMessage($deliveredMessage);
                    }
                }
            } catch (RetryableException $e) {
                // maybe next time we'll get more luck
            }
        }

        return null;
    }

    protected function redeliverMessages(): void
    {
        $now = $this->now();
        if (null === $this->redeliverMessagesLastExecutedAt) {
            $this->redeliverMessagesLastExecutedAt = $now;
        } elseif (($now->durationSince($this->redeliverMessagesLastExecutedAt))->inSeconds() < 1) {
            return;
        }

        $update = $this->getConnection()->createQueryBuilder()
            ->update($this->getContext()->getTableName())
            ->set('delivery_id', ':deliveryId')
            ->set('redelivered', ':redelivered')
            ->andWhere('redeliver_after < :now')
            ->andWhere('delivery_id IS NOT NULL')
            ->setParameter('now', $now->unixTime()->inSeconds(), DbalType::BIGINT)
            ->setParameter('deliveryId', null, DbalType::GUID)
            ->setParameter('redelivered', true, DbalType::BOOLEAN)
        ;

        try {
            // In DBAL 4.x, execute() is replaced with executeStatement()
            if (method_exists($update, 'execute')) {
                $update->execute();
            } else {
                $update->executeStatement();
            }

            $this->redeliverMessagesLastExecutedAt = $now;
        } catch (RetryableException $e) {
            // maybe next time we'll get more luck
        }
    }

    protected function removeExpiredMessages(): void
    {
        $now = $this->now();
        if (null === $this->removeExpiredMessagesLastExecutedAt) {
            $this->removeExpiredMessagesLastExecutedAt = $now;
        } elseif ($now->durationSince($this->removeExpiredMessagesLastExecutedAt)->inSeconds() < 1) {
            return;
        }

        $delete = $this->getConnection()->createQueryBuilder()
            ->delete($this->getContext()->getTableName())
            ->andWhere('(time_to_live IS NOT NULL) AND (time_to_live < :now)')
            ->andWhere('delivery_id IS NULL')
            ->andWhere('redelivered = :redelivered')

            ->setParameter('now', $now->unixTime()->inSeconds(), DbalType::BIGINT)
            ->setParameter('redelivered', false, DbalType::BOOLEAN)
        ;

        try {
            // In DBAL 4.x, execute() is replaced with executeStatement()
            if (method_exists($delete, 'execute')) {
                $delete->execute();
            } else {
                $delete->executeStatement();
            }
        } catch (RetryableException $e) {
            // maybe next time we'll get more luck
        }

        $this->removeExpiredMessagesLastExecutedAt = $now;
    }

    private function deleteMessage(string $deliveryId): void
    {
        if (empty($deliveryId)) {
            throw new LogicException(sprintf('Expected record was removed but it is not. Delivery id: "%s"', $deliveryId));
        }

        $this->getConnection()->delete(
            $this->getContext()->getTableName(),
            ['delivery_id' => $deliveryId],
            ['delivery_id' => DbalType::GUID]
        );
    }
}
