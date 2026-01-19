<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages;

use Ecotone\DataProtection\Attribute\UsingSensitiveData;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\DataProtection\Attribute\WithSensitiveHeaders;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

#[UsingSensitiveData]
#[WithSensitiveHeaders(['foo', 'bar'])]
#[WithSensitiveHeader('fos')]
class FullyObfuscatedMessage
{
    public function __construct(
        public TestClass $class,
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
