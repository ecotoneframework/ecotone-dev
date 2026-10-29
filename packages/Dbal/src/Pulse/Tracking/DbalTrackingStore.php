<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Pulse\Tracking;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Dbal\Database\PulseTrackingTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactories;
use Ecotone\Messaging\Support\InvalidArgumentException;
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
final class DbalTrackingStore implements TrackingStore
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private PulseTrackingTableManager $tableManager,
        private EcotoneClockInterface $clock,
        private CachedConnectionFactories $cachedConnectionFactories,
    ) {
    }

    public function record(Tracking $tracking): void
    {
        $connection = $this->connection();
        try {
            $connection->insert($this->tableManager->getTableName(), [
                'tracing_id' => $tracking->tracingId,
                'category' => $tracking->category,
                'type' => $tracking->type,
                'reference' => $tracking->reference,
                'tenant' => $tracking->tenant,
                'started_at' => $tracking->startedAtInMicroseconds,
            ], ['started_at' => Types::BIGINT]);
        } catch (UniqueConstraintViolationException) {
            throw InvalidArgumentException::create(TrackingAlreadyRecorded::message($tracking->tracingId));
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function startedSince(int $sinceInMicroseconds, ?string $category, ?string $type, ?string $reference, int $limit): array
    {
        $query = $this->connection()->createQueryBuilder()
            ->select('*')
            ->from($this->tableManager->getTableName())
            ->where('started_at >= :since')
            ->setParameter('since', $sinceInMicroseconds, Types::BIGINT)
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('tracing_id', 'DESC')
            ->setMaxResults($limit);
        foreach (['category' => $category, 'type' => $type, 'reference' => $reference] as $column => $value) {
            if ($value !== null) {
                $query->andWhere("{$column} = :{$column}")->setParameter($column, $value);
            }
        }

        try {
            $rows = $query->executeQuery()->fetchAllAssociative();
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }

        return array_map(fn (array $row): Tracking => new Tracking($row['tracing_id'], $row['category'], $row['type'], $row['reference'], $row['tenant'], (int) $row['started_at']), $rows);
    }

    public function withinTransaction(Closure $work): mixed
    {
        return $this->connection()->transactional($work);
    }

    public function activeTenant(): ?string
    {
        return $this->connectionFactory instanceof MultiTenantConnectionFactory && $this->connectionFactory->hasActiveTenant() ? $this->connectionFactory->currentActiveTenant() : null;
    }

    private function connection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->cachedConnectionFactories->cachedFor(new DbalReconnectableConnectionFactory($this->connectionFactory, $this->clock))->createContext();

        return $context->getDbalConnection();
    }
}
