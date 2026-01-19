<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages;

use Ecotone\DataProtection\Attribute\UsingSensitiveData;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

#[UsingSensitiveData]
#[WithSensitiveHeader('foo')]
#[WithSensitiveHeader('bar')]
class PartiallyObfuscatedMessage
{
    public function __construct(
        public TestClass $class,
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
