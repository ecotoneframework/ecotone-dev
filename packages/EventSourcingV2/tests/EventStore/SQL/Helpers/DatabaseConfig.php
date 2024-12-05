<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\SQL\Helpers;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Ecotone\EventSourcingV2\EventStore\Dbal\Connection;
use Ecotone\EventSourcingV2\EventStore\Dbal\Doctrine\DoctrineConnection;
use Ecotone\EventSourcingV2\EventStore\Dbal\Pdo\PdoConnection;
use Ecotone\EventSourcingV2\EventStore\SQL\MysqlEventStore;
use Ecotone\EventSourcingV2\EventStore\SQL\PostgresEventStore;

class DatabaseConfig
{
    public function __construct(
        public string $db = 'pg',
        public string $driver = 'pdo',
        public string $connectionString = '',
    ) {
    }

    public function getConnectionString(): string
    {
        if (! empty($this->connectionString)) {
            return $this->connectionString;
        } else {
            $envKey = match ($this->db) {
                'pg' => 'DATABASE_POSTGRES',
                'mysql' => 'DATABASE_MYSQL',
                default => throw new \InvalidArgumentException("Unknown db: {$this->db}"),
            };
            return getenv($envKey) ?: throw new \InvalidArgumentException("Missing env var: {$envKey}");
        }
    }

    public function bindConnectionString(): self
    {
        return new self(
            db: $this->db,
            driver: $this->driver,
            connectionString: $this->getConnectionString(),
        );
    }

    public function getConnection(): Connection
    {
        return match ($this->driver) {
            'pdo' => new PdoConnection($this->getPdoConnection()),
            'doctrine' => new DoctrineConnection($this->getDoctrineConnection()),
            default => throw new \InvalidArgumentException("Unknown driver: {$this->driver}"),
        };
    }

    public function createEventStore(
        array $projectors = [],
        bool $ignoreUnknownProjectors = true,
        string $eventTableName = 'es_event',
        string $streamTableName = 'es_stream',
        string $subscriptionTableName = 'es_subscription',
        string $projectionTableName = 'es_projection',
        Connection $connection = null,
    ): PostgresEventStore|MysqlEventStore
    {
        $className = $this->getEventStoreClass();
        $eventStore = new $className(
            $connection ?? $this->getConnection(),
            $projectors,
            $ignoreUnknownProjectors,
            $eventTableName,
            $streamTableName,
            $subscriptionTableName,
            $projectionTableName
        );
//        $eventStore->schemaUp();
        return $eventStore;
    }

    /**
     * @return class-string<PostgresEventStore|MysqlEventStore>
     */
    public function getEventStoreClass(): string
    {
        return match ($this->db) {
            'pg' => PostgresEventStore::class,
            'mysql' => MysqlEventStore::class,
            default => throw new \InvalidArgumentException("Unknown db: {$this->db}"),
        };
    }

    public function toString()
    {
        return "{$this->db}::{$this->driver}::{$this->getConnectionString()}";
    }

    public static function fromString(string $config): self
    {
        $parts = explode('::', $config);
        return new self(
            db: \array_shift($parts),
            driver: \array_shift($parts),
            connectionString: implode('::', $parts),
        );
    }

    private function getDoctrineConnection(): \Doctrine\DBAL\Connection
    {
        $parser = new DsnParser([
            'mysql'      => 'pdo_mysql',
            'postgres'   => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'pgsql'      => 'pdo_pgsql',
        ]);
        return DriverManager::getConnection($parser->parse($this->getConnectionString()));
    }

    private function getPdoConnection(): \PDO
    {
        return $this->getDoctrineConnection()->getNativeConnection();
    }
}