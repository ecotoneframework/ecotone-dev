<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints;

use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;

#[Asynchronous('test')]
class EventHandlerWithAnnotatedMethodWithoutPayload
{
    #[WithSensitiveHeader('foo')]
    #[WithSensitiveHeader('bar')]
    #[WithSensitiveHeader('fos')]
    #[EventHandler(listenTo: 'event', endpointId: 'test.obfuscateAnnotatedEndpoints.eventHandler.annotatedMethodWithoutPayload')]
    public function annotatedMethodWithoutPayload(
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived(null, $headers);
    }
}
