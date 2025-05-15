<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\Projecting\Lifecycle\ProjectionLifecycleStateStorage;
use Enqueue\Dbal\DbalConnectionFactory;

class DbalProjectionLifecycleStateStorage implements ProjectionLifecycleStateStorage
{
    private const STATE_INITIALIZED = 'initialized';
    private Connection $connection;
    private bool $initialized = false;
    public function __construct(DbalConnectionFactory $connectionFactory, private string $tableName = 'ecotone_projection_lifecycle_state')
    {
        $this->connection = $connectionFactory->createContext()->getDbalConnection();
    }

    public function init(string $projectionName): bool
    {
        $this->createSchema();

        $statement = match(true) {
            $this->connection->getDatabasePlatform() instanceof MySQLPlatform => <<<SQL
                    INSERT INTO {$this->tableName} (projection_name, state) VALUES (:projectionName, :state)
                    ON DUPLICATE KEY UPDATE projection_name = projection_name
                    SQL,
            default => <<<SQL
                    INSERT INTO {$this->tableName} (projection_name, state) VALUES (:projectionName, :state)
                    ON CONFLICT DO NOTHING
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
        $this->createSchema();

        $rowsAffected = $this->connection->executeStatement(<<<SQL
            DELETE FROM {$this->tableName} WHERE projection_name = :projectionName
            SQL, [
            'projectionName' => $projectionName,
        ]);

        return $rowsAffected > 0;
    }

    public function createSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
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