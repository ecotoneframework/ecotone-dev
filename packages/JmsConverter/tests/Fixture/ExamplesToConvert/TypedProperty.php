<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

/**
 * licence Apache-2.0
 */
class TypedProperty
{
    private int $data;

    /**
     * TypedProperty constructor.
     * @param $data
     */
    public function __construct(int $data)
    {
        $this->data = $data;
    }
}
