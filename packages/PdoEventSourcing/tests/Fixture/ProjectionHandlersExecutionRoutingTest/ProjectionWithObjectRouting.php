<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest;

use Ecotone\Api\EventHandler;
use Ecotone\Api\FromStream;
use Ecotone\Api\Projection;

#[Projection(self::NAME)]
#[FromStream(AnAggregate::STREAM_NAME)]
class ProjectionWithObjectRouting
{
    public const NAME = 'projection_with_object_routing';
    public array $events = [];

    #[EventHandler]
    public function onEvent(AnEvent $event): void
    {
        $this->events[] = $event;
    }
}
