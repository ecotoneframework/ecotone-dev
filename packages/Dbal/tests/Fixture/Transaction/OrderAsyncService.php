<?php

namespace Fixture\Transaction;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;
use Ecotone\Api\Reference;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class OrderAsyncService
{
    #[CommandHandler('order.prepare')]
    public function prepare(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory)
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $connection->executeStatement(<<<SQL
                DROP TABLE IF EXISTS orders
            SQL);
        $connection->executeStatement(<<<SQL
                CREATE TABLE orders (id VARCHAR(255) PRIMARY KEY)
            SQL);
    }

    #[Asynchronous('async')]
    #[CommandHandler('order.register', endpointId: 'order.register.endpoint')]
    public function register(string $order, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $connection->executeStatement(<<<SQL
                INSERT INTO orders VALUES (:order)
            SQL, ['order' => $order]);

        throw new InvalidArgumentException('test');
    }

    #[QueryHandler('order.getRegistered')]
    public function hasOrder(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): array
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $isTableExists = $this->doesTableExists($connection);

        if (! $isTableExists) {
            return [];
        }

        return $connection->executeQuery(<<<SQL
                SELECT * FROM orders
            SQL)->fetchFirstColumn();
    }

    private function doesTableExists(\Doctrine\DBAL\Connection $connection)
    {
        $schemaManager = $connection->createSchemaManager();

        return $schemaManager->tablesExist(['orders']);
    }
}
