<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

use ArrayObject;

/**
 * licence Apache-2.0
 */
class PropertyWithMixedArrayType
{
    public function __construct(private ArrayObject $data)
    {
    }
}
