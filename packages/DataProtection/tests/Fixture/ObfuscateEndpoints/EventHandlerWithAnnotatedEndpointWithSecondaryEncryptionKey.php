<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints;

use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;

#[Asynchronous('test')]
class EventHandlerWithAnnotatedEndpointWithSecondaryEncryptionKey
{
    #[Sensitive]
    #[WithEncryptionKey('secondary')]
    #[WithSensitiveHeader('foo')]
    #[WithSensitiveHeader('bar')]
    #[EventHandler(endpointId: 'test.obfuscateAnnotatedEndpoints.eventHandler.annotatedMethodWithSecondaryEncryptionKey')]
    public function annotatedMethodWithSecondaryEncryptionKey(
        SomeMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
