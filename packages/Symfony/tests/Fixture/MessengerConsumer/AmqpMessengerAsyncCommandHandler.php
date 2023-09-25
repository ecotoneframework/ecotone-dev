<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class AmqpMessengerAsyncCommandHandler
{
    /** @var array<int, array{AmqpExampleCommand, array<string, string>}> */
    private array $commands = [];

    #[Asynchronous('amqp_async')]
    #[CommandHandler('amqp.test.example_command', 'amqp.messenger_async_endpoint')]
    public function test(AmqpExampleCommand $command, array $headers): void
    {
        $this->commands[] = ['id' => $command->id, 'headers' => $headers];
    }

    #[QueryHandler('amqp.consumer.getCommands')]
    public function getCommandAtIndex(): array
    {
        return $this->commands;
    }
}
