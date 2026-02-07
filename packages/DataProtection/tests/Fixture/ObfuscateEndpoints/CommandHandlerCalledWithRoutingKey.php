<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateEndpoints;

use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;

#[Asynchronous('test')]
class CommandHandlerCalledWithRoutingKey
{
    #[WithSensitiveHeader('foo')]
    #[WithSensitiveHeader('bar')]
    #[WithSensitiveHeader('fos')]
    #[CommandHandler(routingKey: 'command', endpointId: 'test.obfuscateAnnotatedEndpoints.commandHandler.calledWithRoutingKey')]
    public function calledWithRoutingKey(
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived(null, $headers);
    }
}
