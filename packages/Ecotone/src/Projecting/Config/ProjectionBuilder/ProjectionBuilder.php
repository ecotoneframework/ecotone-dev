<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config\ProjectionBuilder;

use Ecotone\AnnotationFinder\AnnotatedDefinition;

class ProjectionBuilder
{
    /**
     * @param AnnotatedDefinition[] $projectionEventHandlers
     */
    public function __construct(
        public readonly string  $projectionName,
        public readonly array   $projectionEventHandlers,
        public readonly ?string $partitionHeaderName,
        public readonly ?string $asynchronousChannelName
    )
    {
    }
}