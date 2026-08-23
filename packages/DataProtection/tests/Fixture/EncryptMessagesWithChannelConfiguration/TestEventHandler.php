<?php

namespace Test\Ecotone\DataProtection\Fixture\EncryptMessagesWithChannelConfiguration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Parameter\Headers;
use Ecotone\Api\Attribute\Parameter\Reference;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;

#[Asynchronous('test')]
class TestEventHandler
{
    #[EventHandler(endpointId: 'test.EncryptMessagesWithChannelConfiguration.eventHandler.withPayload')]
    public function withPayload(
        SomeMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(listenTo: 'event', endpointId: 'test.EncryptMessagesWithChannelConfiguration.eventHandler.withoutPayload')]
    public function withoutPayload(
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived(null, $headers);
    }

    #[EventHandler(endpointId: 'test.EncryptMessagesWithChannelConfiguration.eventHandler.handleAnnotatedMessage')]
    public function handleAnnotatedMessage(
        AnnotatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
