<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages;

use Ecotone\DataProtection\Attribute\UsingSensitiveData;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

#[UsingSensitiveData]
class FullyObfuscatedMessage
{
    public function __construct(
        public TestClass $class,
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
