<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

/**
 * licence Apache-2.0
 */
class NullableProperty
{
    private ?int $data;

    public function __construct(?int $data)
    {
        $this->data = $data;
    }
}
