<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Fixture\PersistingSensitiveEvents;

use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

class SomeCommand
{
    public function __construct(
        public string $id,
        public string $value,
        public TestEnum $enum,
        public TestClass $object,
    ) {
    }
}
