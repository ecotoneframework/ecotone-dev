<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\AsynchronousMessageHandler;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * @internal
 * licence Apache-2.0
 */
final class AsyncCommandHandler
{
    /** @var array<int, array{payload: AsyncCommand, headers: array<string, mixed>}> */
    private array $processedCommands = [];

    #[Asynchronous('async_channel')]
    #[CommandHandler('execute.async_command', 'async_channel_endpoint')]
    public function handleAsyncCommand(AsyncCommand $command, array $headers): void
    {
        $this->processedCommands[] = [
            'payload' => $command,
            'headers' => $headers
        ];
    }

    #[QueryHandler('consumer.getProcessedCommands')]
    public function getProcessedCommands(): array
    {
        return $this->processedCommands;
    }

    #[QueryHandler('consumer.getProcessedCommandsCount')]
    public function getProcessedCommandsCount(): int
    {
        return count($this->processedCommands);
    }

    #[QueryHandler('consumer.getLastProcessedCommand')]
    public function getLastProcessedCommand(): ?array
    {
        return end($this->processedCommands) ?: null;
    }

    public function reset(): void
    {
        $this->processedCommands = [];
    }
}
