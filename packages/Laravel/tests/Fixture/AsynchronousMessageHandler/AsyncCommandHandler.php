<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class AsyncCommandHandler
{
    /** @var array<int, array{ExampleCommand, array<string, string>}> */
    private array $commands = [];

    #[Asynchronous('async_channel')]
    #[CommandHandler('execute.example_command', 'async_channel_endpoint')]
    public function collect(ExampleCommand $command, array $headers): void
    {
        $this->commands[] = ['payload' => $command, 'headers' => $headers];
    }

    #[Asynchronous('async_channel')]
    #[CommandHandler('execute.fail', 'async_channel_fail')]
    public function fail(ExampleCommand $command, array $headers): void
    {
        $this->commands[] = ['payload' => $command, 'headers' => $headers];
        throw new InvalidArgumentException('failed');
    }

    #[Asynchronous('async_channel')]
    #[CommandHandler('execute.noPayload', 'async_channel_no_payload')]
    public function routingNoPayload(#[Headers] $headers): void
    {
        $this->commands[] = ['payload' => [], 'headers' => $headers];
    }

    #[Asynchronous('async_channel')]
    #[CommandHandler('execute.arrayPayload', 'async_channel_array_payload')]
    public function routingArrayPayload(array $payload, array $headers): void
    {
        $this->commands[] = ['payload' => $payload, 'headers' => $headers];
    }

    #[Asynchronous('async_channel')]
    #[CommandHandler('execute.stringPayload', 'async_channel_string_payload')]
    public function routingStringPayload(string $payload, array $headers): void
    {
        $this->commands[] = ['payload' => $payload, 'headers' => $headers];
    }

    #[QueryHandler('consumer.getMessages')]
    public function getCommandAtIndex(): array
    {
        return $this->commands;
    }
}
