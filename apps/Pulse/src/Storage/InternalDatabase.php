<?php

declare(strict_types=1);

namespace App\Storage;

use Closure;
use Doctrine\DBAL\Connection;
use Ecotone\Api\Dbal\DbalConnectionFactory;

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
        return $this->connection()->transactional(function (Connection $connection) use ($work): mixed {
            $this->takeWriteLock($connection);

            return $work($connection);
        });
    }

    private function takeWriteLock(Connection $connection): void
    {
        $connection->executeStatement('UPDATE ' . InternalTables::SERVICE_RECORD . ' SET service = service WHERE 1 = 0');
    }
}
