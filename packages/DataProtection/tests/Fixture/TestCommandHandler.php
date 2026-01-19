<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\FullyObfuscatedMessage;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\MessageWithSecondaryKeyEncryption;
use Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages\PartiallyObfuscatedMessage;

#[Asynchronous('test')]
class TestCommandHandler
{
    #[CommandHandler(endpointId: 'test.FullyObfuscatedMessage')]
    public function handleFullyObfuscatedMessage(
        #[Payload] FullyObfuscatedMessage $message,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceivedMessage($message);
    }

    #[CommandHandler(endpointId: 'test.PartiallyObfuscatedMessage')]
    public function handlePartiallyObfuscatedMessage(
        #[Payload] PartiallyObfuscatedMessage $message,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceivedMessage($message);
    }

    #[CommandHandler(endpointId: 'test.MessageWithSecondaryKeyEncryption')]
    public function handleMessageWithSecondaryKeyEncryption(
        #[Payload] MessageWithSecondaryKeyEncryption $message,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceivedMessage($message);
    }
}
