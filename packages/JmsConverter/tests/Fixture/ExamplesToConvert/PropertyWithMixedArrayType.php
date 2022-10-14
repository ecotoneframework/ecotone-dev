<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

use ArrayObject;

class PropertyWithMixedArrayType
{
    public function __construct(private ArrayObject $data)
    {
    }
}
