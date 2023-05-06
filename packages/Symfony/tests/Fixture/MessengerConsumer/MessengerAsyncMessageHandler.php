<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use InvalidArgumentException;

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
        throw new InvalidArgumentException('failed');
    }

    #[Asynchronous('messenger_async')]
    #[CommandHandler('execute.noPayload', 'messenger_async_no_payload')]
    public function routingNoPayload(#[Headers] $headers): void
    {
        $this->commands[] = ['payload' => [], 'headers' => $headers];
    }

    #[Asynchronous('messenger_async')]
    #[CommandHandler('execute.arrayPayload', 'messenger_async_array_payload')]
    public function routingArrayPayload(array $payload, array $headers): void
    {
        $this->commands[] = ['payload' => $payload, 'headers' => $headers];
    }

    #[QueryHandler('consumer.getMessages')]
    public function getCommandAtIndex(): array
    {
        return $this->commands;
    }
}
