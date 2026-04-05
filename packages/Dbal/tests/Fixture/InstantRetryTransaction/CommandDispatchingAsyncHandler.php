<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\InstantRetryTransaction;

use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\CommandBus;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
final class CommandDispatchingAsyncHandler
{
    private int $commandHandlerCallCount = 0;

    public function __construct(private ConnectionFactory $connectionFactory)
    {
    }

    #[Asynchronous('async')]
    #[CommandHandler('dispatch.sql.command', endpointId: 'dispatchSqlCommandEndpoint')]
    public function dispatch(string $payload, CommandBus $commandBus): void
    {
        $commandBus->sendWithRouting('execute.sql.command', $payload);
    }

    #[CommandHandler('execute.sql.command')]
    public function executeSqlCommand(string $payload): void
    {
        $connection = $this->getTransactionalConnection();

        $this->commandHandlerCallCount++;
        if ($this->commandHandlerCallCount === 1) {
            $connection->executeStatement("INSERT INTO persons (person_id, name) VALUES (1, 'duplicate')");
        }

        $connection->executeStatement(
            "INSERT INTO persons (person_id, name) VALUES (?, ?)",
            [$this->commandHandlerCallCount + 100, 'attempt-' . $this->commandHandlerCallCount],
        );
    }

    public function getCommandHandlerCallCount(): int
    {
        return $this->commandHandlerCallCount;
    }

    private function getTransactionalConnection(): \Doctrine\DBAL\Connection
    {
        $factory = CachedConnectionFactory::createFor(
            new DbalReconnectableConnectionFactory($this->connectionFactory),
        );

        /** @var DbalContext $context */
        $context = $factory->createContext();

        return $context->getDbalConnection();
    }
}
