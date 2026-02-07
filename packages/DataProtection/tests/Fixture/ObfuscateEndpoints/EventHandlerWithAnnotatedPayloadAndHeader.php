<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints;

use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;

#[Asynchronous('test')]
class EventHandlerWithAnnotatedPayloadAndHeader
{
    #[WithSensitiveHeader('bar')]
    #[EventHandler(endpointId: 'test.obfuscateAnnotatedEndpoints.eventHandler.annotatedMethod')]
    public function annotatedMethod(
        #[Sensitive] #[WithEncryptionKey('secondary')] SomeMessage $message,
        #[Sensitive] #[Header('foo')] string $foo,
        #[Header('bar')] string $bar,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, ['foo' => $foo, 'bar' => $bar]);
    }
}
