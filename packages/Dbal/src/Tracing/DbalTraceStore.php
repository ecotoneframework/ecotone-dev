<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

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
use Ecotone\Tracing\TraceLedger;
use Ecotone\Tracing\TraceProgress;
use Ecotone\Tracing\TraceStore;
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
                foreach ($traceSpans as $span) {
                    $this->insertSpan($connection, $span);
                }
            });
        }
    }

    public function markCompletedIfDone(string $traceId, DatePoint $now): bool
    {
        $connection = $this->connection();

        return $this->onTables(fn (): bool => TraceLedger::progressOf($traceId, $this->spansOf($connection, $traceId))->isCompleted() && $this->markCompleted($connection, $traceId, $now));
    }

    public function markNotified(string $traceId): void
    {
        $connection = $this->connection();
        $this->onTables(fn (): int => $connection->executeStatement(
            "UPDATE {$this->tracesTableManager->getTableName()} SET notified_at = :notifiedAt WHERE trace_id = :traceId AND completed_at IS NOT NULL AND notified_at IS NULL",
            ['notifiedAt' => $this->clock->now()->unixTime()->inMicroseconds(), 'traceId' => $traceId],
            ['notifiedAt' => Types::BIGINT],
        ));
    }

    public function completedButNotNotifiedBefore(DatePoint $completedBefore, int $limit): array
    {
        return $this->onTables(fn (): array => $this->connection()->createQueryBuilder()
            ->select('trace_id')
            ->from($this->tracesTableManager->getTableName())
            ->andWhere('completed_at IS NOT NULL')
            ->andWhere('notified_at IS NULL')
            ->andWhere('completed_at < :completedBefore')
            ->setParameter('completedBefore', $completedBefore->unixTime()->inMicroseconds(), Types::BIGINT)
            ->orderBy('completed_at')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn());
    }

    public function progressOf(string $traceId): TraceProgress
    {
        return $this->onTables(fn (): TraceProgress => TraceLedger::progressOf($traceId, $this->spansOf($this->connection(), $traceId)));
    }

    public function removeExpiredTraces(DatePoint $completedTracesStartedBefore, DatePoint $anyTraceStartedBefore): void
    {
        $connection = $this->connection();
        $this->onTables(function () use ($connection, $completedTracesStartedBefore, $anyTraceStartedBefore): void {
            $expired = "SELECT trace_id FROM {$this->tracesTableManager->getTableName()} WHERE started_at < :anyStartedBefore OR (completed_at IS NOT NULL AND started_at < :completedStartedBefore)";
            $parameters = ['anyStartedBefore' => $anyTraceStartedBefore->unixTime()->inMicroseconds(), 'completedStartedBefore' => $completedTracesStartedBefore->unixTime()->inMicroseconds()];
            $types = ['anyStartedBefore' => Types::BIGINT, 'completedStartedBefore' => Types::BIGINT];
            $connection->executeStatement("DELETE FROM {$this->spansTableManager->getTableName()} WHERE trace_id IN ({$expired})", $parameters, $types);
            $connection->executeStatement("DELETE FROM {$this->tracesTableManager->getTableName()} WHERE started_at < :anyStartedBefore OR (completed_at IS NOT NULL AND started_at < :completedStartedBefore)", $parameters, $types);
        });
    }

    /**
     * @param Span[] $traceSpans
     */
    private function claimTrace(Connection $connection, string $traceId, array $traceSpans): void
    {
        $startedAt = min(array_map(fn (Span $span): int => $span->startedAt->unixTime()->inMicroseconds(), $traceSpans));
        $tableName = $this->tracesTableManager->getTableName();
        $sql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? "INSERT INTO {$tableName} (trace_id, started_at, completed_at, notified_at) VALUES (:traceId, :startedAt, NULL, NULL) ON DUPLICATE KEY UPDATE completed_at = NULL, notified_at = NULL"
            : "INSERT INTO {$tableName} (trace_id, started_at, completed_at, notified_at) VALUES (:traceId, :startedAt, NULL, NULL) ON CONFLICT (trace_id) DO UPDATE SET completed_at = NULL, notified_at = NULL";

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

    private function insertSpan(Connection $connection, Span $span): void
    {
        $columns = [
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
        $placeholders = implode(', ', array_map(fn (string $column): string => ':' . $column, array_keys($columns)));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->spansTableManager->getTableName(), implode(', ', array_keys($columns)), $placeholders);
        $sql .= $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' ON DUPLICATE KEY UPDATE span_id = span_id' : ' ON CONFLICT (trace_id, span_id) DO NOTHING';

        $connection->executeStatement($sql, $columns, ['started_at' => Types::BIGINT, 'ended_at' => Types::BIGINT]);
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
