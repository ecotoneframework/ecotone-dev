<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\FullyObfuscatedMessage;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\MessageWithSecondaryKeyEncryption;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\PartiallyObfuscatedMessage;

#[Asynchronous('test')]
class TestEventHandler
{
    #[EventHandler(endpointId: 'test.FullyObfuscatedMessage')]
    public function handleFullyObfuscatedMessage(
        #[Payload] FullyObfuscatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(endpointId: 'test.PartiallyObfuscatedMessage')]
    public function handlePartiallyObfuscatedMessage(
        #[Payload] PartiallyObfuscatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(endpointId: 'test.MessageWithSecondaryKeyEncryption')]
    public function handleMessageWithSecondaryKeyEncryption(
        #[Payload] MessageWithSecondaryKeyEncryption $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
