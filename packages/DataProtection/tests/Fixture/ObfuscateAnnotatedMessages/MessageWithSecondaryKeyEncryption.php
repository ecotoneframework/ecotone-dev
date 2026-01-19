<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages;

use Ecotone\DataProtection\Attribute\UsingSensitiveData;

#[UsingSensitiveData('secondary')]
class MessageWithSecondaryKeyEncryption
{
    public function __construct(
        public string $argument
    ) {
    }
}
