<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanEvent;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Dbal\Database\SpansTableManager;
use Ecotone\Dbal\Database\TracesTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Interop\Queue\ConnectionFactory;

use function json_decode;
use function json_encode;

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
final class DbalTraceStore implements TraceStore
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private TracesTableManager $tracesTableManager,
        private SpansTableManager $spansTableManager,
        private EcotoneClockInterface $clock,
    ) {
    }

    public function append(array $spans): void
    {
        $spansByTrace = [];
        foreach ($spans as $span) {
            $spansByTrace[$span->traceId][] = $span;
        }

        $connection = $this->connection();
        foreach ($spansByTrace as $traceId => $traceSpans) {
            $this->onTables(function () use ($connection, $traceId, $traceSpans): void {
                $this->claimTrace($connection, $traceId, $traceSpans);
                $this->insertSpans($connection, $traceSpans);
            });
        }
    }

    public function markCompletedIfDone(string $traceId, DatePoint $now): bool
    {
        $connection = $this->connection();

        return $this->onTables(fn (): bool => TraceLedger::progressOf($traceId, $this->spansOf($connection, $traceId))->isCompleted() && $this->markCompleted($connection, $traceId, $now));
    }

    public function stepsOf(string $traceId): array
    {
        return $this->onTables(fn (): array => $this->spansOf($this->connection(), $traceId));
    }

    public function progressOf(string $traceId): TraceProgress
    {
        return $this->onTables(fn (): TraceProgress => TraceLedger::progressOf($traceId, $this->spansOf($this->connection(), $traceId)));
    }

    public function removeExpiredTraces(DatePoint $completedTracesStartedBefore, DatePoint $anyTraceStartedBefore, int $batchSize): void
    {
        $connection = $this->connection();
        do {
            $removed = $this->onTables(fn (): int => $connection->transactional(fn (): int => $this->removeExpiredBatch($connection, $completedTracesStartedBefore, $anyTraceStartedBefore, $batchSize)));
        } while ($removed === $batchSize);
    }

    public function withinTransaction(Closure $work): mixed
    {
        return $this->connection()->transactional($work);
    }

    private function removeExpiredBatch(Connection $connection, DatePoint $completedTracesStartedBefore, DatePoint $anyTraceStartedBefore, int $batchSize): int
    {
        $traceIds = $connection->createQueryBuilder()
            ->select('trace_id')
            ->from($this->tracesTableManager->getTableName())
            ->where('started_at < :anyStartedBefore OR (completed_at IS NOT NULL AND started_at < :completedStartedBefore)')
            ->setParameter('anyStartedBefore', $anyTraceStartedBefore->unixTime()->inMicroseconds(), Types::BIGINT)
            ->setParameter('completedStartedBefore', $completedTracesStartedBefore->unixTime()->inMicroseconds(), Types::BIGINT)
            ->setMaxResults($batchSize)
            ->executeQuery()
            ->fetchFirstColumn();
        if ($traceIds === []) {
            return 0;
        }

        foreach ([$this->spansTableManager->getTableName(), $this->tracesTableManager->getTableName()] as $tableName) {
            $connection->createQueryBuilder()
                ->delete($tableName)
                ->where('trace_id IN (:traceIds)')
                ->setParameter('traceIds', $traceIds, ArrayParameterType::STRING)
                ->executeStatement();
        }

        return count($traceIds);
    }

    /**
     * @param Span[] $traceSpans
     */
    private function claimTrace(Connection $connection, string $traceId, array $traceSpans): void
    {
        $startedAt = min(array_map(fn (Span $span): int => $span->startedAt->unixTime()->inMicroseconds(), $traceSpans));
        $tableName = $this->tracesTableManager->getTableName();
        $sql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? "INSERT INTO {$tableName} (trace_id, started_at, completed_at) VALUES (:traceId, :startedAt, NULL) ON DUPLICATE KEY UPDATE completed_at = NULL"
            : "INSERT INTO {$tableName} (trace_id, started_at, completed_at) VALUES (:traceId, :startedAt, NULL) ON CONFLICT (trace_id) DO UPDATE SET completed_at = NULL";

        $connection->executeStatement($sql, ['traceId' => $traceId, 'startedAt' => $startedAt], ['startedAt' => Types::BIGINT]);
    }

    private function markCompleted(Connection $connection, string $traceId, DatePoint $now): bool
    {
        return $connection->executeStatement(
            "UPDATE {$this->tracesTableManager->getTableName()} SET completed_at = :completedAt WHERE trace_id = :traceId AND completed_at IS NULL",
            ['completedAt' => $now->unixTime()->inMicroseconds(), 'traceId' => $traceId],
            ['completedAt' => Types::BIGINT],
        ) === 1;
    }

    /**
     * @param Span[] $spans
     */
    private function insertSpans(Connection $connection, array $spans): void
    {
        $columns = ['span_id', 'trace_id', 'parent_span_id', 'name', 'kind', 'status', 'status_description', 'attributes', 'events', 'started_at', 'ended_at'];
        $rows = [];
        $parameters = [];
        $types = [];
        foreach (array_values($spans) as $index => $span) {
            $values = [
                'span_id' => $span->spanId,
                'trace_id' => $span->traceId,
                'parent_span_id' => $span->parentSpanId,
                'name' => $span->name,
                'kind' => $span->kind->value,
                'status' => $span->status->value,
                'status_description' => $span->statusDescription,
                'attributes' => json_encode($span->attributes, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
                'events' => json_encode(array_map(fn (SpanEvent $event): array => [
                    'name' => $event->name,
                    'attributes' => (object) $event->attributes,
                    'at' => $event->at->unixTime()->inMicroseconds(),
                ], $span->events), JSON_THROW_ON_ERROR),
                'started_at' => $span->startedAt->unixTime()->inMicroseconds(),
                'ended_at' => $span->endedAt->unixTime()->inMicroseconds(),
            ];
            $placeholders = [];
            foreach ($values as $column => $value) {
                $placeholders[] = ':' . $column . $index;
                $parameters[$column . $index] = $value;
            }
            $types['started_at' . $index] = Types::BIGINT;
            $types['ended_at' . $index] = Types::BIGINT;
            $rows[] = '(' . implode(', ', $placeholders) . ')';
        }
        $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $this->spansTableManager->getTableName(), implode(', ', $columns), implode(', ', $rows));
        $sql .= $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' ON DUPLICATE KEY UPDATE span_id = span_id' : ' ON CONFLICT (trace_id, span_id) DO NOTHING';

        $connection->executeStatement($sql, $parameters, $types);
    }

    /**
     * @return Span[]
     */
    private function spansOf(Connection $connection, string $traceId): array
    {
        $rows = $connection->createQueryBuilder()
            ->select('*')
            ->from($this->spansTableManager->getTableName())
            ->andWhere('trace_id = :traceId')
            ->setParameter('traceId', $traceId)
            ->orderBy('ended_at')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): Span => new Span(
            $row['trace_id'],
            $row['span_id'],
            $row['parent_span_id'],
            $row['name'],
            SpanKind::from($row['kind']),
            json_decode($row['attributes'], true, flags: JSON_THROW_ON_ERROR),
            SpanStatus::from($row['status']),
            $row['status_description'],
            array_map(
                fn (array $event): SpanEvent => new SpanEvent($event['name'], $event['attributes'], self::datePointOf((int) $event['at'])),
                json_decode($row['events'], true, flags: JSON_THROW_ON_ERROR),
            ),
            self::datePointOf((int) $row['started_at']),
            self::datePointOf((int) $row['ended_at']),
        ), $rows);
    }

    private static function datePointOf(int $unixTimeInMicroseconds): DatePoint
    {
        return (new DatePoint('@0'))->setUnixTime(Duration::microseconds($unixTimeInMicroseconds));
    }

    private function onTables(callable $statement): mixed
    {
        try {
            return $statement();
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained(MissingTableFailure::explained($failure, $this->tracesTableManager), $this->spansTableManager);
        }
    }

    private function connection(): Connection
    {
        /** @var DbalContext $context */
        $context = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($this->connectionFactory, $this->clock))->createContext();

        return $context->getDbalConnection();
    }
}
