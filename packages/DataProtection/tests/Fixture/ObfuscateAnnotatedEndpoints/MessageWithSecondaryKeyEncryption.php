<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedEndpoints;

use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

class MessageWithSecondaryKeyEncryption
{
    public function __construct(
        public TestClass $class,
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
