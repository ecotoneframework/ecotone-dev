<?php

namespace Test\Ecotone\DataProtection\Fixture;

class TestClass
{
    public function __construct(
        public string $argument,
        public TestEnum $enum,
    ) {
    }
}
