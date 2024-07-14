<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

use JMS\Serializer\Annotation as Serializer;

/**
 * licence Apache-2.0
 */
class PropertyWithTypeAndMetadataType
{
    /**
     * @var string
     * @Serializer\Type("integer")
     */
    #[Serializer\Type('integer')]
    private $data;

    /**
     * ObjectWithAnnotationMetadataDefined constructor.
     * @param $data
     */
    public function __construct($data)
    {
        $this->data = $data;
    }
}
