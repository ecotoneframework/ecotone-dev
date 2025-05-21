<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config\ProjectionBuilder;

use Ecotone\Messaging\Attribute\Endpoint\Priority;

class ProjectionBuilder
{
    /**
     * @param array<string, ProjectionEventHandlerConfiguration> $projectionEventHandlers key is event name
     * @param array<string, Priority> $projectionEventTriggerPriorities key is event name
     */
    public function __construct(
        public readonly string  $projectionName,
        public readonly array   $projectionEventHandlers,
        public readonly ?string $asynchronousChannelName,
        public readonly array   $projectionEventTriggerPriorities,
        public readonly ?string $partitionHeaderName = null,
    )
    {
    }
}