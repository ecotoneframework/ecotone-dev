<?php

namespace Test\Ecotone\DataProtection\Fixture\EncryptAnnotatedMessages;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Parameter\Headers;
use Ecotone\Api\Attribute\Parameter\Payload;
use Ecotone\Api\Attribute\Parameter\Reference;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessageWithSecondaryEncryptionKey;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\MessageWithCustomConverter;
use Test\Ecotone\DataProtection\Fixture\MessageWithSensitiveProperties;
use Test\Ecotone\DataProtection\Fixture\MessageWithSensitiveProperty;

#[Asynchronous('test')]
class TestEventHandler
{
    #[EventHandler(endpointId: 'test.EncryptAnnotatedMessages.eventHandler.AnnotatedMessage')]
    public function handleAnnotatedMessage(
        #[Payload] AnnotatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(endpointId: 'test.EncryptAnnotatedMessages.eventHandler.AnnotatedMessageWithSecondaryEncryptionKey')]
    public function handleAnnotatedMessageWithSecondaryEncryptionKey(
        #[Payload] AnnotatedMessageWithSecondaryEncryptionKey $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(endpointId: 'test.EncryptAnnotatedMessages.eventHandler.AnnotatedMessageWithSensitiveProperties')]
    public function handleAnnotatedMessageWithSensitiveProperties(
        #[Payload] MessageWithSensitiveProperties $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(endpointId: 'test.EncryptAnnotatedMessages.eventHandler.MessageWithSensitiveProperty')]
    public function handleMessageWithSensitiveProperty(
        #[Payload] MessageWithSensitiveProperty $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }

    #[EventHandler(endpointId: 'test.EncryptAnnotatedMessages.eventHandler.MessageWithCustomConverter')]
    public function handleMessageWithCustomConverter(
        #[Payload] MessageWithCustomConverter $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
