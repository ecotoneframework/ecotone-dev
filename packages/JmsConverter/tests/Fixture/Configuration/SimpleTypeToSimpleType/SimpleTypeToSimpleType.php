<?php

namespace Test\Ecotone\JMSConverter\Fixture\Configuration\SimpleTypeToSimpleType;

use Ecotone\Messaging\Attribute\Converter;

/**
 * licence Apache-2.0
 */
class SimpleTypeToSimpleType
{
    #[Converter]
    public function convert(string $type): string
    {
    }
}
