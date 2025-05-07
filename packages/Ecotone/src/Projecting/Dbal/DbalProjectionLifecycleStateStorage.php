<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\Projecting\Lifecycle\ProjectionLifecycleStateStorage;

class DbalProjectionLifecycleStateStorage implements ProjectionLifecycleStateStorage
{
    private const STATE_INITIALIZED = 'initialized';
    public function __construct(private Connection $connection, private string $tableName = 'ecotone_projection_lifecycle_state')
    {
    }

    public function init(string $projectionName): bool
    {
        $statement = match(true) {
            $this->connection->getDatabasePlatform() instanceof MySQLPlatform => <<<SQL
                    INSERT INTO {$this->tableName} (projection_name, state) VALUES (:projectionName, :state)
                    ON DUPLICATE KEY UPDATE projection_name = projection_name
                    SQL,
            default => <<<SQL
                    INSERT INTO {$this->tableName} (projection_name, state) VALUES (:projectionName, :state)
                    ON DUPLICATE KEY DO NOTHING
                    SQL,
        };

        $rowsAffected = $this->connection->executeStatement($statement, [
            'projectionName' => $projectionName,
            'state' => self::STATE_INITIALIZED,
        ]);

        return $rowsAffected > 0;
    }

    public function delete(string $projectionName): bool
    {
        $rowsAffected = $this->connection->executeStatement(<<<SQL
            DELETE FROM {$this->tableName} WHERE projection_name = :projectionName
            SQL, [
            'projectionName' => $projectionName,
        ]);

        return $rowsAffected > 0;
    }

    public function createSchema(): void
    {
        $this->connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                projection_name VARCHAR(255) NOT NULL,
                state VARCHAR(255) NOT NULL,
                PRIMARY KEY (projection_name)
            )
            SQL
        );
    }
}