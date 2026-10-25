<?php

declare(strict_types=1);

namespace App\Trace;

use App\Snapshot\ServiceScope;
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

    public function read(ServiceScope $scope, string $queryKey): QueryRecord
    {
        return $this->readFrom($this->database->connection(), $scope, $queryKey);
    }

    /**
     * @param Closure(QueryRecord): QueryRecord $change
     */
    public function update(ServiceScope $scope, string $queryKey, Closure $change): QueryRecord
    {
        return $this->database->inTransaction(function (Connection $connection) use ($scope, $queryKey, $change): QueryRecord {
            $now = $this->clock->now()->getTimestamp();
            $expiresAt = $now + self::LIFETIME_SECONDS;
            $connection->executeStatement('DELETE FROM ' . InternalTables::QUERY_RECORD . ' WHERE expires_at < ?', [$now]);
            $connection->executeStatement('DELETE FROM ' . InternalTables::QUERY_POINTER . ' WHERE expires_at < ?', [$now]);

            $record = $change($this->readFrom($connection, $scope, $queryKey));
            $connection->executeStatement(
                'INSERT INTO ' . InternalTables::QUERY_RECORD . ' (service, tenant, query_key, record, expires_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(service, tenant, query_key) DO UPDATE SET record = excluded.record, expires_at = excluded.expires_at',
                [$scope->service, $scope->tenantColumn(), $queryKey, json_encode($record->toArray(), JSON_THROW_ON_ERROR), $expiresAt]
            );
            if ($record->queryId !== null) {
                $connection->executeStatement(
                    'INSERT INTO ' . InternalTables::QUERY_POINTER . ' (query_id, service, tenant, query_key, expires_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(query_id) DO UPDATE SET expires_at = excluded.expires_at',
                    [$record->queryId, $scope->service, $scope->tenantColumn(), $queryKey, $expiresAt]
                );
            }

            return $record;
        });
    }

    /**
     * @return array{ServiceScope, string}|null
     */
    public function whoAskedWith(string $queryId): ?array
    {
        $row = $this->database->connection()->fetchAssociative('SELECT service, tenant, query_key FROM ' . InternalTables::QUERY_POINTER . ' WHERE query_id = ?', [$queryId]);

        return $row === false ? null : [$row['tenant'] === '' ? ServiceScope::ofService($row['service']) : ServiceScope::ofTenant($row['service'], $row['tenant']), $row['query_key']];
    }

    private function readFrom(Connection $connection, ServiceScope $scope, string $queryKey): QueryRecord
    {
        $stored = $connection->fetchOne('SELECT record FROM ' . InternalTables::QUERY_RECORD . ' WHERE service = ? AND tenant = ? AND query_key = ?', [$scope->service, $scope->tenantColumn(), $queryKey]);

        return is_string($stored) ? QueryRecord::fromArray(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) : QueryRecord::empty();
    }
}
