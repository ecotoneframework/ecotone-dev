<?php

declare(strict_types=1);

namespace App\Trace;

use App\Storage\InternalDatabase;
use App\Storage\InternalTables;
use Closure;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class QueryStore
{
    private const LIFETIME_SECONDS = 86400;

    public function __construct(
        private InternalDatabase $database,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
    ) {
    }

    public function read(string $service, string $queryKey): QueryRecord
    {
        return $this->readFrom($this->database->connection(), $service, $queryKey);
    }

    /**
     * @param Closure(QueryRecord): QueryRecord $change
     */
    public function update(string $service, string $queryKey, Closure $change): QueryRecord
    {
        return $this->database->inTransaction(function (Connection $connection) use ($service, $queryKey, $change): QueryRecord {
            $now = $this->clock->now()->getTimestamp();
            $expiresAt = $now + self::LIFETIME_SECONDS;
            $connection->executeStatement('DELETE FROM ' . InternalTables::QUERY_RECORD . ' WHERE expires_at < ?', [$now]);
            $connection->executeStatement('DELETE FROM ' . InternalTables::QUERY_POINTER . ' WHERE expires_at < ?', [$now]);

            $record = $change($this->readFrom($connection, $service, $queryKey));
            $connection->executeStatement(
                'INSERT INTO ' . InternalTables::QUERY_RECORD . ' (service, query_key, record, expires_at) VALUES (?, ?, ?, ?) ON CONFLICT(service, query_key) DO UPDATE SET record = excluded.record, expires_at = excluded.expires_at',
                [$service, $queryKey, json_encode($record->toArray(), JSON_THROW_ON_ERROR), $expiresAt]
            );
            if ($record->queryId !== null) {
                $connection->executeStatement(
                    'INSERT INTO ' . InternalTables::QUERY_POINTER . ' (query_id, service, query_key, expires_at) VALUES (?, ?, ?, ?) ON CONFLICT(query_id) DO UPDATE SET expires_at = excluded.expires_at',
                    [$record->queryId, $service, $queryKey, $expiresAt]
                );
            }

            return $record;
        });
    }

    /**
     * @return array{string, string}|null
     */
    public function whoAskedWith(string $queryId): ?array
    {
        $row = $this->database->connection()->fetchAssociative('SELECT service, query_key FROM ' . InternalTables::QUERY_POINTER . ' WHERE query_id = ?', [$queryId]);

        return $row === false ? null : [$row['service'], $row['query_key']];
    }

    private function readFrom(Connection $connection, string $service, string $queryKey): QueryRecord
    {
        $stored = $connection->fetchOne('SELECT record FROM ' . InternalTables::QUERY_RECORD . ' WHERE service = ? AND query_key = ?', [$service, $queryKey]);

        return is_string($stored) ? QueryRecord::fromArray(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) : QueryRecord::empty();
    }
}
