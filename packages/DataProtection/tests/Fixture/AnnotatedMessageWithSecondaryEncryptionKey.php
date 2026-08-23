<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Api\Attribute\Sensitive;
use Ecotone\DataProtection\Api\Attribute\WithEncryptionKey;

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
