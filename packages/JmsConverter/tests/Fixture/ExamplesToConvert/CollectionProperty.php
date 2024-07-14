<?php

namespace Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert;

/**
 * licence Apache-2.0
 */
class CollectionProperty
{
    /**
     * @var PropertyWithTypeAndMetadataType[]
     */
    private array $collection;

    /**
     * CollectionProperty constructor.
     * @param PropertyWithTypeAndMetadataType[] $collection
     */
    public function __construct(array $collection)
    {
        $this->collection = $collection;
    }
}
