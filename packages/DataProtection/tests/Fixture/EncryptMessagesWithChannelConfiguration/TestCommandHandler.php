<?php

namespace Test\Ecotone\DataProtection\Fixture\EncryptMessagesWithChannelConfiguration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\Reference;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;

#[Asynchronous('test')]
class TestCommandHandler
{
    #[CommandHandler(endpointId: 'test.EncryptMessagesWithChannelConfiguration.commandHandler.withPayload')]
    public function withPayload(
        SomeMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(routingKey: 'command', endpointId: 'test.EncryptMessagesWithChannelConfiguration.commandHandler.withoutPayload')]
    public function withoutPayload(
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived(null, $headers);
    }

    #[CommandHandler(endpointId: 'test.EncryptMessagesWithChannelConfiguration.commandHandler.handleAnnotatedMessage')]
    public function handleAnnotatedMessage(
        AnnotatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
