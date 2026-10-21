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

    public function read(string $service): ServiceRecord
    {
        return $this->readFrom($this->database->connection(), $service);
    }

    /**
     * @param Closure(ServiceRecord): ServiceRecord $change
     */
    public function update(string $service, Closure $change): ServiceRecord
    {
        return $this->database->inTransaction(function (Connection $connection) use ($service, $change): ServiceRecord {
            $record = $change($this->readFrom($connection, $service));
            $connection->executeStatement(
                'INSERT INTO ' . InternalTables::SERVICE_RECORD . ' (service, record) VALUES (?, ?) ON CONFLICT(service) DO UPDATE SET record = excluded.record',
                [$service, json_encode($record->toArray(), JSON_THROW_ON_ERROR)]
            );

            return $record;
        });
    }

    private function readFrom(Connection $connection, string $service): ServiceRecord
    {
        $stored = $connection->fetchOne('SELECT record FROM ' . InternalTables::SERVICE_RECORD . ' WHERE service = ?', [$service]);

        return is_string($stored) ? ServiceRecord::fromArray(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)) : ServiceRecord::empty();
    }
}
