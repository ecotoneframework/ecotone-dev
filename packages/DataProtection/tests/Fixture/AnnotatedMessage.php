<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Attribute\Sensitive;

#[Sensitive]
class AnnotatedMessage
{
    public function __construct(
        public TestClass $sensitiveObject,
        public TestEnum $sensitiveEnum,
        public string $sensitiveProperty
    ) {
    }
}
