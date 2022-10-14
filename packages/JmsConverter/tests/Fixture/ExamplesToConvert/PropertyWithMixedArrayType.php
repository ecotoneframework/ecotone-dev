<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

class PropertyWithMixedArrayType
{
    public function __construct(private \ArrayObject $data)
    {
    }
}
