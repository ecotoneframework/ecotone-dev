<?php

namespace Test\Ecotone\DataProtection\Fixture\EncryptMessagesWithChannelConfiguration;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;

#[Asynchronous('test')]
class TestEventHandler
{
    #[EventHandler(endpointId: 'test.EncryptMessagesWithChannelConfiguration.eventHandler.withPayload')]
    public function handleFullyObfuscatedMessage(
        SomeMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(listenTo: 'event', endpointId: 'test.EncryptMessagesWithChannelConfiguration.eventHandler.withoutPayload')]
    public function handleRoutingKey(
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived(null, $headers);
    }
}
