<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;

#[Sensitive]
#[WithEncryptionKey('secondary')]
class AnnotatedMessageWithSecondaryEncryptionKey
{
    public function __construct(
        public TestClass $sensitiveObject,
        public TestEnum $sensitiveEnum,
        public string $sensitiveProperty
    ) {
    }
}
