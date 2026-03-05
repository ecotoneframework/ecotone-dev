<?php

namespace Test\Ecotone\DataProtection\Fixture\EncryptAnnotatedMessages;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessageWithSecondaryEncryptionKey;
use Test\Ecotone\DataProtection\Fixture\MessageWithSensitiveProperties;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\MessageWithCustomConverter;
use Test\Ecotone\DataProtection\Fixture\MessageWithSensitiveProperty;

#[Asynchronous('test')]
class TestCommandHandler
{
    #[CommandHandler(endpointId: 'test.EncryptAnnotatedMessages.commandHandler.AnnotatedMessage')]
    public function handleAnnotatedMessage(
        #[Payload] AnnotatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(endpointId: 'test.EncryptAnnotatedMessages.commandHandler.AnnotatedMessageWithSecondaryEncryptionKey')]
    public function handleAnnotatedMessageWithSecondaryEncryptionKey(
        #[Payload] AnnotatedMessageWithSecondaryEncryptionKey $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(endpointId: 'test.EncryptAnnotatedMessages.commandHandler.AnnotatedMessageWithSensitiveProperties')]
    public function handleAnnotatedMessageWithSensitiveProperties(
        #[Payload] MessageWithSensitiveProperties $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(endpointId: 'test.EncryptAnnotatedMessages.commandHandler.MessageWithSensitiveProperty')]
    public function handleMessageWithSensitiveProperty(
        #[Payload] MessageWithSensitiveProperty $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[CommandHandler(endpointId: 'test.EncryptAnnotatedMessages.commandHandler.MessageWithCustomConverter')]
    public function handleMessageWithCustomConverter(
        #[Payload] MessageWithCustomConverter $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
