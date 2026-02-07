<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateMessages;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessageWithSecondaryEncryptionKey;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessageWithSensitiveHeaders;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;

#[Asynchronous('test')]
class TestCommandHandler
{
    #[CommandHandler(endpointId: 'test.obfuscateAnnotatedMessages.commandHandler.AnnotatedMessage')]
    public function handleAnnotatedMessage(
        #[Payload] AnnotatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(endpointId: 'test.obfuscateAnnotatedMessages.commandHandler.AnnotatedMessageWithSecondaryEncryptionKey')]
    public function handleAnnotatedMessageWithSecondaryEncryptionKey(
        #[Payload] AnnotatedMessageWithSecondaryEncryptionKey $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(endpointId: 'test.obfuscateAnnotatedMessages.commandHandler.AnnotatedMessageWithSensitiveHeaders')]
    public function handleAnnotatedMessageWithSensitiveHeaders(
        #[Payload] AnnotatedMessageWithSensitiveHeaders $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
