<?php

declare(strict_types=1);

namespace Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\MessageEndpoint\Splitter;

use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\Annotation\MessageEndpoint;
use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\Annotation\SomeHandlerAnnotation;

#[MessageEndpoint]
/**
 * licence Apache-2.0
 */
class SplitterExample
{
    #[SomeHandlerAnnotation]
    public function split(string $payload): array
    {
        return [];
    }
}
