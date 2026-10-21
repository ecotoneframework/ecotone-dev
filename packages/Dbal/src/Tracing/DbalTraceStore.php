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
use Ecotone\Dbal\Database\MissingColumnFailure;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Dbal\Database\SpansTableManager;
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
        private SpansTableManager $spansTableManager,
        private EcotoneClockInterface $clock,
    ) {
    }

    public function append(array $spans): void
    {
        $connection = $this->connection();
        $this->onTables(function () use ($connection, $spans): void {
            $plan = FlowEntries::plan($spans, $this->storedTracesOf($connection, $spans));
            $this->insertSpans($connection, $spans, $plan);
            $this->markFailed($connection, $plan->traceIdsToMarkFailed);
        });
    }

    public function flowTypeStatistics(int $limit, int $window): array
    {
        return $this->onTables(function () use ($limit, $window): array {
            $connection = $this->connection();
            $table = $this->spansTableManager->getTableName();
            $groups = $connection->fetchAllAssociative(
                "SELECT flow_type, COUNT(*) AS runs FROM {$table} WHERE flow_type IS NOT NULL GROUP BY flow_type ORDER BY runs DESC, flow_type ASC LIMIT {$limit}",
            );

            return array_values(array_filter(array_map(fn (array $group): ?FlowTypeStatistics => FlowTypeStatistics::overWindow(
                $group['flow_type'],
                (int) $group['runs'],
                array_map(fn (array $row): array => [
                    'startedAt' => (int) $row['started_at'],
                    'durationInMicroseconds' => (int) $row['ended_at'] - (int) $row['started_at'],
                    'status' => (string) $row['flow_status'],
                ], $connection->createQueryBuilder()
                    ->select('started_at', 'ended_at', 'flow_status')
                    ->from($table)
                    ->where('flow_type = :flowType')
                    ->setParameter('flowType', $group['flow_type'])
                    ->orderBy('started_at', 'DESC')
                    ->addOrderBy('trace_id', 'DESC')
                    ->setMaxResults($window)
                    ->executeQuery()
                    ->fetchAllAssociative()),
            ), $groups)));
        });
    }

    public function tracesOf(string $flowType, int $page, int $perPage): TracePage
    {
        return $this->onTables(function () use ($flowType, $page, $perPage): TracePage {
            $connection = $this->connection();
            $table = $this->spansTableManager->getTableName();
            $total = (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table} WHERE flow_type = :flowType", ['flowType' => $flowType]);
            $entries = $connection->createQueryBuilder()
                ->select('*')
                ->from($table)
                ->where('flow_type = :flowType')
                ->setParameter('flowType', $flowType)
                ->orderBy('started_at', 'DESC')
                ->addOrderBy('trace_id', 'DESC')
                ->setFirstResult(($page - 1) * $perPage)
                ->setMaxResults($perPage)
                ->executeQuery()
                ->fetchAllAssociative();
            $spansByTrace = [];
            foreach ($this->spansOfTraces($connection, array_column($entries, 'trace_id')) as $span) {
                $spansByTrace[$span->traceId][] = $span;
            }

            return new TracePage($total, array_map(
                fn (array $entry): TraceSummary => TraceSummary::of($this->spanOf($entry), $entry['flow_status'], $spansByTrace[$entry['trace_id']] ?? []),
                $entries,
            ));
        });
    }

    public function stepsOf(string $traceId): array
    {
        return $this->onTables(fn (): array => $this->spansOf($this->connection(), $traceId));
    }

    public function progressOf(string $traceId): TraceProgress
    {
        return $this->onTables(fn (): TraceProgress => TraceLedger::progressOf($traceId, $this->spansOf($this->connection(), $traceId)));
    }

    public function removeTracesStartedBefore(DatePoint $startedBefore, int $batchSize): void
    {
        $connection = $this->connection();
        do {
            $removed = $this->onTables(fn (): int => $connection->transactional(fn (): int => $this->removeBatchStartedBefore($connection, $startedBefore, $batchSize)));
        } while ($removed === $batchSize);
    }

    public function withinTransaction(Closure $work): mixed
    {
        return $this->connection()->transactional($work);
    }

    private function removeBatchStartedBefore(Connection $connection, DatePoint $startedBefore, int $batchSize): int
    {
        $traceIds = $connection->createQueryBuilder()
            ->select('DISTINCT trace_id')
            ->from($this->spansTableManager->getTableName())
            ->where('started_at < :startedBefore')
            ->setParameter('startedBefore', $startedBefore->unixTime()->inMicroseconds(), Types::BIGINT)
            ->setMaxResults($batchSize)
            ->executeQuery()
            ->fetchFirstColumn();
        if ($traceIds === []) {
            return 0;
        }

        $connection->createQueryBuilder()
            ->delete($this->spansTableManager->getTableName())
            ->where('trace_id IN (:traceIds)')
            ->setParameter('traceIds', $traceIds, ArrayParameterType::STRING)
            ->executeStatement();

        return count($traceIds);
    }

    /**
     * @param Span[] $spans
     * @return array<string, array{spanIds: array<string, true>, hasEntry: bool, hasError: bool}>
     */
    private function storedTracesOf(Connection $connection, array $spans): array
    {
        $stored = [];
        $rows = $connection->createQueryBuilder()
            ->select('trace_id', 'span_id', 'flow_type', 'status')
            ->from($this->spansTableManager->getTableName())
            ->where('trace_id IN (:traceIds)')
            ->setParameter('traceIds', array_values(array_unique(array_map(fn (Span $span): string => $span->traceId, $spans))), ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $trace = $stored[$row['trace_id']] ?? ['spanIds' => [], 'hasEntry' => false, 'hasError' => false];
            $trace['spanIds'][$row['span_id']] = true;
            $trace['hasEntry'] = $trace['hasEntry'] || $row['flow_type'] !== null;
            $trace['hasError'] = $trace['hasError'] || $row['status'] === SpanStatus::Error->value;
            $stored[$row['trace_id']] = $trace;
        }

        return $stored;
    }

    /**
     * @param string[] $traceIds
     */
    private function markFailed(Connection $connection, array $traceIds): void
    {
        if ($traceIds === []) {
            return;
        }

        $connection->createQueryBuilder()
            ->update($this->spansTableManager->getTableName())
            ->set('flow_status', ':status')
            ->where('flow_type IS NOT NULL')
            ->andWhere('trace_id IN (:traceIds)')
            ->setParameter('status', FlowEntries::STATUS_ERROR)
            ->setParameter('traceIds', $traceIds, ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * @param string[] $traceIds
     * @return Span[]
     */
    private function spansOfTraces(Connection $connection, array $traceIds): array
    {
        if ($traceIds === []) {
            return [];
        }

        $rows = $connection->createQueryBuilder()
            ->select('*')
            ->from($this->spansTableManager->getTableName())
            ->where('trace_id IN (:traceIds)')
            ->setParameter('traceIds', $traceIds, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->spanOf(...), $rows);
    }

    /**
     * @param Span[] $spans
     */
    private function insertSpans(Connection $connection, array $spans, FlowEntryPlan $plan): void
    {
        $columns = ['span_id', 'trace_id', 'parent_span_id', 'name', 'kind', 'status', 'status_description', 'attributes', 'events', 'started_at', 'ended_at', 'flow_type', 'flow_status'];
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
                'flow_type' => $plan->entriesBySpanId[$span->spanId]['flowType'] ?? null,
                'flow_status' => $plan->entriesBySpanId[$span->spanId]['status'] ?? null,
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

        return array_map($this->spanOf(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function spanOf(array $row): Span
    {
        return new Span(
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
        );
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
            throw MissingTableFailure::explained(MissingColumnFailure::explained($failure, $this->spansTableManager), $this->spansTableManager);
        }
    }

    private function connection(): Connection
    {
        /** @var DbalContext $context */
        $context = CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($this->connectionFactory, $this->clock))->createContext();

        return $context->getDbalConnection();
    }
}
