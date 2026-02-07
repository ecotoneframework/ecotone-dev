<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;

#[Sensitive]
#[WithSensitiveHeader('foo')]
#[WithSensitiveHeader('bar')]
#[WithSensitiveHeader('fos')]
class AnnotatedMessageWithSensitiveHeaders
{
    public function __construct(
        public TestClass $class,
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
