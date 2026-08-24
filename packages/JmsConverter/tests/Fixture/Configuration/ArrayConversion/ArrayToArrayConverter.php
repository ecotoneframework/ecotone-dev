<?php

namespace Test\Ecotone\JMSConverter\Fixture\Configuration\ArrayConversion;

use Ecotone\Api\Converter;

/**
 * licence Apache-2.0
 */
class ArrayToArrayConverter
{
    #[Converter]
    public function convert(array $data): array
    {
    }
}
