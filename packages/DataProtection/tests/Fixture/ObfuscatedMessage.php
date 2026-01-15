<?php

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\DataProtection\Attribute\UsingSensitiveData;

#[UsingSensitiveData]
class ObfuscatedMessage
{
    public function __construct(
        private string $foo,
        private string $bar,
    ) {
    }
}
