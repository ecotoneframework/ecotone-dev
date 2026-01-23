<?php

namespace Test\Ecotone\DataProtection\Fixture\ObfuscateChannel;

use Test\Ecotone\DataProtection\Fixture\TestClass;
use Test\Ecotone\DataProtection\Fixture\TestEnum;

class ObfuscatedMessage
{
    public function __construct(
        public TestClass $class,
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
