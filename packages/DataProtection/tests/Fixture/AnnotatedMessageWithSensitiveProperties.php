<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Attribute\Sensitive;

#[Sensitive]
class AnnotatedMessageWithSensitiveProperties
{
    public function __construct(
        #[Sensitive] public TestClass $sensitiveObject,
        #[Sensitive] public TestEnum $sensitiveEnum,
        public string $property,
        #[Sensitive] public string $sensitiveProperty,
    ) {
    }
}
