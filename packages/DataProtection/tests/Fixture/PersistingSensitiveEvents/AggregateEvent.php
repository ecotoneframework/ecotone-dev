<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Fixture\PersistingSensitiveEvents;

use Ecotone\DataProtection\Attribute\Sensitive;
use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

#[Sensitive]
final readonly class AggregateEvent
{
    public function __construct(
        public string $id,
        #[Sensitive] public string $sensitiveValue,
        #[Sensitive] public TestEnum $sensitiveEnum,
        #[Sensitive] public TestClass $sensitiveObject,
    ) {
    }
}
