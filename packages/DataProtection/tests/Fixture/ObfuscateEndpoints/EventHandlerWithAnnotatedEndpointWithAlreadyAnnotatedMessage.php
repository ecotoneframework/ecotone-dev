<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints;

use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\DataProtection\Fixture\AnnotatedMessage;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;

#[Asynchronous('test')]
class EventHandlerWithAnnotatedEndpointWithAlreadyAnnotatedMessage
{
    #[WithSensitiveHeader('foo')]
    #[WithSensitiveHeader('bar')]
    #[WithSensitiveHeader('fos')]
    #[EventHandler(endpointId: 'test.obfuscateAnnotatedEndpoints.eventHandler.annotatedMethod')]
    public function annotatedMethod(
        #[Sensitive] #[WithEncryptionKey('secondary')] AnnotatedMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
