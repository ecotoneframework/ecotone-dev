<?php

namespace Test\Ecotone\DataProtection\Fixture\EncryptMessagesWithAnnotatedEndpoint;

use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Test\Ecotone\DataProtection\Fixture\MessageReceiver;
use Test\Ecotone\DataProtection\Fixture\SomeMessage;

#[Asynchronous('test')]
class CommandHandlerWithAnnotatedEndpointWithSecondaryEncryptionKey
{
    #[WithSensitiveHeader('foo')]
    #[WithSensitiveHeader('bar')]
    #[CommandHandler(endpointId: 'test.commandHandler.EncryptMessagesWithAnnotatedEndpoint.annotatedMethodWithSecondaryEncryptionKey')]
    public function annotatedMethodWithSecondaryEncryptionKey(
        #[Sensitive] #[WithEncryptionKey('secondary')] SomeMessage $message,
        #[Headers] array $headers,
        #[Reference] MessageReceiver $messageReceiver,
    ): void {
        $messageReceiver->withReceived($message, $headers);
    }
}
