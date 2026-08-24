<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\Api\DataProtection\Sensitive;
use Ecotone\Api\DataProtection\WithEncryptionKey;

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
