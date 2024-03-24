<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\MessageHandlerFlow;

use Ecotone\Messaging\Attribute\MessageHandler;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Messaging\Message;

final readonly class ExampleMessageHandler
{
    #[CommandHandler('handleCommand', outputChannelName: 'handleMessage')]
    public function handleCommand(Message $message): Message
    {
        return $message;
    }

    #[MessageHandler('handleMessage')]
    public function handle(Message $message): void
    {

    }
}