<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\Transaction\Nested;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\CommandBus;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;

final class NestedDbalHandlers
{
    #[CommandHandler('nested.prepare')]
    public function prepare(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS nested_events');
        $connection->executeStatement('CREATE TABLE nested_events (id INT PRIMARY KEY)');
    }

    #[CommandHandler('nested.inner')]
    public function inner(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): void
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $connection->executeStatement('INSERT INTO nested_events (id) VALUES (1)');
    }

    #[CommandHandler('nested.outer')]
    public function outer(#[Reference] CommandBus $commandBus): void
    {
        // Trigger a nested synchronous command bus call
        $commandBus->sendWithRouting('nested.inner');
    }

    #[QueryHandler('nested.count')]
    public function count(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): int
    {
        $connection = $connectionFactory->createContext()->getDbalConnection();
        return (int) $connection->executeQuery('SELECT COUNT(*) FROM nested_events')->fetchOne();
    }
}
