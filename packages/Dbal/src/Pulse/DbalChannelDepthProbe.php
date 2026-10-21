<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Pulse;

use Doctrine\DBAL\ArrayParameterType;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Connection\DbalType;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelDepth;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelProbe;
use Interop\Queue\ConnectionFactory;
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
 * @internal
 */
final class DbalChannelDepthProbe implements PulseChannelProbe
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private EnqueueTableManager $tableManager,
        private EcotoneClockInterface $clock,
    ) {
    }

    public function depthsOf(array $destinationByChannel, int $budgetInMilliseconds): array
    {
        $context = $this->connectionFactory->createContext();
        $connection = $context instanceof DbalContext ? $context->getDbalConnection() : throw new \LogicException('Pulse measures DBAL channels only on a DBAL connection, but the connection reference resolved to ' . $context::class . '.');

        $now = $this->clock->now()->getTimestamp();
        $insideTransaction = $connection->isTransactionActive();
        $savepoint = 'ecotone_pulse_channel_depth';
        if ($insideTransaction) {
            $connection->createSavepoint($savepoint);
        }
        try {
            $rows = $connection->executeQuery(
                "SELECT queue,
                    SUM(CASE WHEN delivery_id IS NULL AND (delayed_until IS NULL OR delayed_until <= :nowForReady) THEN 1 ELSE 0 END) AS ready_count,
                    SUM(CASE WHEN delivery_id IS NULL AND delayed_until > :nowForDelayed THEN 1 ELSE 0 END) AS delayed_count,
                    SUM(CASE WHEN delivery_id IS NOT NULL THEN 1 ELSE 0 END) AS in_flight_count
                 FROM {$context->getTableName()} WHERE queue IN (:queues) GROUP BY queue",
                ['nowForReady' => $now, 'nowForDelayed' => $now, 'queues' => array_values($destinationByChannel)],
                ['nowForReady' => DbalType::BIGINT, 'nowForDelayed' => DbalType::BIGINT, 'queues' => ArrayParameterType::STRING],
            )->fetchAllAssociative();
            if ($insideTransaction) {
                $connection->releaseSavepoint($savepoint);
            }
        } catch (Throwable $failure) {
            if ($insideTransaction) {
                $connection->rollbackSavepoint($savepoint);
            }
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }

        $depthByQueue = [];
        foreach ($rows as $row) {
            $depthByQueue[$row['queue']] = PulseChannelDepth::measured((int) $row['ready_count'], (int) $row['delayed_count'], (int) $row['in_flight_count']);
        }

        $depths = [];
        foreach ($destinationByChannel as $channel => $queue) {
            $depths[$channel] = $depthByQueue[$queue] ?? PulseChannelDepth::measured(0, 0, 0);
        }

        return $depths;
    }
}
