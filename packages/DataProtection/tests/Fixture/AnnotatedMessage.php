<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Attribute\Sensitive;

#[Sensitive]
class AnnotatedMessage
{
    public function __construct(
        public TestClass $class,
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
