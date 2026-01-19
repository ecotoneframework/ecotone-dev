<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;

#[Asynchronous('test')]
class TestEventHandler
{
    #[EventHandler(endpointId: 'test.eventHandler.FullyObfuscatedMessage')]
    public function handleFullyObfuscatedMessage(
        #[Payload] ObfuscatedMessage $message,
        #[Headers] array             $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(endpointId: 'test.eventHandler.MessageWithSecondaryKeyEncryption')]
    public function handleMessageWithSecondaryKeyEncryption(
        #[Payload] MessageWithSecondaryKeyEncryption $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
