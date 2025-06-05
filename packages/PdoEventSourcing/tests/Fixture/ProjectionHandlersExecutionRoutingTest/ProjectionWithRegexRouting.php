<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Modelling\Attribute\EventHandler;

#[Projection(self::NAME, AnAggregate::STREAM_NAME)]
class ProjectionWithRegexRouting
{

    public const NAME = 'projection_with_regex_routing';
    public array $events = [];

    #[EventHandler('test.*')]
    public function onEvent(array $event): void
    {
        $this->events[] = $event;
    }
}