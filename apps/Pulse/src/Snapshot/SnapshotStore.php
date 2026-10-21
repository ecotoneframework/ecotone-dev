<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Storage\InternalDatabase;
use App\Storage\InternalTables;
use Closure;
use Doctrine\DBAL\Connection;

final readonly class SnapshotStore
{
    public function __construct(private InternalDatabase $database)
    {
    }

    public function read(ServiceScope $scope): ServiceRecord
    {
        return $this->readFrom($this->database->connection(), $scope);
    }

    /**
     * @param Closure(ServiceRecord): ServiceRecord $change
     */
    public function update(ServiceScope $scope, Closure $change): ServiceRecord
    {
        return $this->database->inTransaction(function (Connection $connection) use ($scope, $change): ServiceRecord {
            $record = $change($this->readFrom($connection, $scope));
            $connection->executeStatement(
                'INSERT INTO ' . InternalTables::SERVICE_RECORD . ' (service, tenant, record) VALUES (?, ?, ?) ON CONFLICT(service, tenant) DO UPDATE SET record = excluded.record',
                [$scope->service, $scope->tenantColumn(), json_encode($record->toArray(), JSON_THROW_ON_ERROR)]
            );

            return $record;
        });
    }

    private function readFrom(Connection $connection, ServiceScope $scope): ServiceRecord
    {
        $stored = $connection->fetchOne('SELECT record FROM ' . InternalTables::SERVICE_RECORD . ' WHERE service = ? AND tenant = ?', [$scope->service, $scope->tenantColumn()]);

        return is_string($stored) ? ServiceRecord::fromArray(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) : ServiceRecord::empty();
    }
}
