<?php

namespace Test\Ecotone\DataProtection\Fixture;

readonly class SomeMessage
{
    public function __construct(
        public TestClass $class,
        public TestEnum $enum,
        public string $argument
    ) {
    }
}
