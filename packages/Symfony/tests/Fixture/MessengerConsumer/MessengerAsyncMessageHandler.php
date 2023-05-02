<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class MessengerAsyncMessageHandler
{
    /** @var array<int, array{ExampleCommand, array<string, string>}> */
    private array $commands = [];

    #[Asynchronous('messenger_async')]
    #[CommandHandler('execute.example_command', 'messenger_async_endpoint')]
    public function collect(ExampleCommand $command, array $headers): void
    {
        $this->commands[] = ['payload' => $command, 'headers' => $headers];
    }

    #[Asynchronous('messenger_async')]
    #[CommandHandler('execute.fail', 'messenger_async_fail')]
    public function fail(ExampleCommand $command, array $headers): void
    {
        $this->commands[] = ['payload' => $command, 'headers' => $headers];
        throw new \InvalidArgumentException("failed");
    }

    #[QueryHandler('consumer.getMessages')]
    public function getCommandAtIndex(): array
    {
        return $this->commands;
    }
}
