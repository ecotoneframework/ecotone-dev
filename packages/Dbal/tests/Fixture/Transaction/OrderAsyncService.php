<?php

namespace Fixture\Transaction;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Enqueue\Dbal\DbalConnectionFactory;
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
        $schemaManager = method_exists($connection, 'getSchemaManager') ? $connection->getSchemaManager() : $connection->createSchemaManager();

        return $schemaManager->tablesExist(['orders']);
    }
}
