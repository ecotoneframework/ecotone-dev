<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateAnnotatedMessages;

use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\UsingSensitiveData;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

#[UsingSensitiveData]
class PartiallyObfuscatedMessage
{
    public function __construct(
        #[Sensitive]
        public TestClass $class,
        #[Sensitive]
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
