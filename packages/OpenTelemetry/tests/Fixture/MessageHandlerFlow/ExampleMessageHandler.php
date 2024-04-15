<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\MessageHandlerFlow;

use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\Attribute\CommandHandler;

final class ExampleMessageHandler
{
    #[CommandHandler('handleCommand', outputChannelName: 'handleMessage')]
    public function handleCommand(Message $message): Message
    {
        return $message;
    }

    #[InternalHandler('handleMessage')]
    public function handle(Message $message): void
    {

    }
}
