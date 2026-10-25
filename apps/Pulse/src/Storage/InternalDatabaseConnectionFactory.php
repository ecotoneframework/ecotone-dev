<?php

declare(strict_types=1);

namespace App\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use PDO;

final class InternalDatabaseConnectionFactory extends DbalConnectionFactory
{
    private ?Connection $connection = null;

    public function __construct(private readonly string $path, private readonly int $busyTimeoutSeconds)
    {
        parent::__construct(['dsn' => 'sqlite:///' . $path, 'connection' => ['driverOptions' => [PDO::ATTR_TIMEOUT => $busyTimeoutSeconds]]]);
    }

    public function establishConnection(): Connection
    {
        if ($this->connection === null) {
            $configuration = (new Configuration())->setMiddlewares([new class () implements Middleware {
                public function wrap(Driver $driver): Driver
                {
                    return new class ($driver) extends AbstractDriverMiddleware {
                        public function connect(array $params): Driver\Connection
                        {
                            return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                                public function beginTransaction(): void
                                {
                                    $this->exec('BEGIN IMMEDIATE');
                                }

                                public function commit(): void
                                {
                                    $this->exec('COMMIT');
                                }

                                public function rollBack(): void
                                {
                                    $this->exec('ROLLBACK');
                                }
                            };
                        }
                    };
                }
            }]);
            $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path, 'driverOptions' => [PDO::ATTR_TIMEOUT => $this->busyTimeoutSeconds]], $configuration);
        }

        return $this->connection;
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }
}
