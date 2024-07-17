<?php

namespace Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\TestingNamespace\Correct;

use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\Annotation\MessageEndpoint;
use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\Annotation\ParameterAttribute;

class ClassWithPromotedConstructorParameterAttribute
{
    public function __construct(
        #[ParameterAttribute, MessageEndpoint] public string $aPromotedProperty,
    )
    {
    }
}