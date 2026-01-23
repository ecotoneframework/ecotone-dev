<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedEndpoints;

use Ecotone\DataProtection\Attribute\UsingSensitiveData;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\DataProtection\Attribute\WithSensitiveHeaders;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;

#[Asynchronous('test')]
class TestCommandHandler
{
    #[CommandHandler(endpointId: 'test.commandHandler.FullyObfuscatedMessage')]
    #[UsingSensitiveData]
    #[WithSensitiveHeaders(['foo', 'bar'])]
    #[WithSensitiveHeader('fos')]
    public function handleFullyObfuscatedMessage(
        #[Payload] ObfuscatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(endpointId: 'test.commandHandler.MessageWithSecondaryKeyEncryption')]
    #[UsingSensitiveData('secondary')]
    #[WithSensitiveHeader('foo')]
    #[WithSensitiveHeader('bar')]
    public function handleMessageWithSecondaryKeyEncryption(
        #[Payload] MessageWithSecondaryKeyEncryption $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(routingKey: 'command', endpointId: 'test.commandHandler.withRoutingKey')]
    #[UsingSensitiveData]
    #[WithSensitiveHeaders(['foo', 'bar'])]
    #[WithSensitiveHeader('fos')]
    public function handleRoutingKey(
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived(null, $headers);
    }
}
