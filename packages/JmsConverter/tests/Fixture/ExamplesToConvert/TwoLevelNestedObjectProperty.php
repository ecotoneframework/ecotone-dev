<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

/**
 * licence Apache-2.0
 */
class TwoLevelNestedObjectProperty
{
    private PropertyWithTypeAndMetadataType $data;

    /**
     * TwoLevelNestedObjectProperty constructor.
     * @param PropertyWithTypeAndMetadataType $data
     */
    public function __construct(PropertyWithTypeAndMetadataType $data)
    {
        $this->data = $data;
    }
}
