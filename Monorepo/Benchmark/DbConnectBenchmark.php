<?php

declare(strict_types=1);

namespace Monorepo\Benchmark;

use Enqueue\Dbal\DbalConnectionFactory;

class DbConnectBenchmark
{
    public function bench_db_connect(): void
    {
        $connectionFactory = new DbalConnectionFactory('pgsql://ecotone:secret@localhost:5432/ecotone');
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $connection->executeQuery('SELECT 1');
    }
}