<?php

declare(strict_types=1);

namespace App\Storage;

use Closure;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Throwable;

final readonly class InternalDatabase
{
    public function __construct(private DbalConnectionFactory $connectionFactory)
    {
    }

    public function connection(): Connection
    {
        return $this->connectionFactory->createContext()->getDbalConnection();
    }

    /**
     * @template T
     * @param Closure(Connection): T $work
     * @return T
     */
    public function inTransaction(Closure $work): mixed
    {
        $connection = $this->connection();
        if ($connection->isTransactionActive()) {
            return $work($connection);
        }

        $connection->executeStatement('BEGIN IMMEDIATE');
        try {
            $result = $work($connection);
            $connection->executeStatement('COMMIT');

            return $result;
        } catch (Throwable $exception) {
            $connection->executeStatement('ROLLBACK');

            throw $exception;
        }
    }
}
