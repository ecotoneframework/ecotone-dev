<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Transaction\ClassRouted;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Enqueue\ConnectionFactory;

final class ClassRoutedOrderService
{
    #[CommandHandler]
    #[WithoutDatabaseTransaction]
    public function prepare(PrepareOrdersByClassCommand $command, #[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();

        $connection->executeStatement(<<<SQL
                DROP TABLE IF EXISTS orders
            SQL);
        $connection->executeStatement(<<<SQL
                CREATE TABLE orders (id VARCHAR(255) PRIMARY KEY)
            SQL);
        $connection->executeStatement(<<<SQL
                INSERT INTO orders VALUES ('milk')
            SQL);

        $this->failIfInsertedRowIsStillLockedByAnOpenTransaction($connection);
    }

    #[QueryHandler('classRoutedOrder.getRegistered')]
    public function getRegistered(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): array
    {
        return $connectionFactory->createContext()->getDbalConnection()
            ->executeQuery('SELECT id FROM orders')
            ->fetchFirstColumn();
    }

    private function failIfInsertedRowIsStillLockedByAnOpenTransaction(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        if (! $platform instanceof PostgreSQLPlatform && ! $platform instanceof AbstractMySQLPlatform) {
            return;
        }

        $dsn = getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
        $probeConnection = (new DbalConnectionFactory($dsn))->createContext()->getDbalConnection();

        if ($platform instanceof PostgreSQLPlatform) {
            $probeConnection->executeStatement('SET lock_timeout = 1000');
        } else {
            $probeConnection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        }

        try {
            $probeConnection->executeQuery('SELECT id FROM orders WHERE id = :id FOR UPDATE', ['id' => 'milk'])->fetchOne();
        } finally {
            $probeConnection->close();
        }
    }
}
