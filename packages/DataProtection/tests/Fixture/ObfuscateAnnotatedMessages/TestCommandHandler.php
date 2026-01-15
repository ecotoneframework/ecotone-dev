<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;

#[Asynchronous('test')]
class TestCommandHandler
{
    #[CommandHandler(endpointId: 'test.FullyObfuscatedMessage')]
    public function handleFullyObfuscatedMessage(FullyObfuscatedMessage $message): void
    {
        dd($message);
    }

    #[CommandHandler(endpointId: 'test.PartiallyObfuscatedMessage')]
    public function handlePartiallyObfuscatedMessage(PartiallyObfuscatedMessage $message): void
    {
        dd($message);
    }
}
