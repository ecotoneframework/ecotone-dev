<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Messaging\Attribute\Endpoint\Priority;
use Ecotone\Modelling\Attribute\EventHandler;

#[Projection(self::NAME, AnAggregate::STREAM_NAME)]
class ProjectionWithMulitpleHandlersForSameEvent
{

    public const NAME = 'projection_with_multiple_handlers';
    public array $events = [];

    #[EventHandler('test.*')]
    public function regex(array $event): void
    {
        $this->events[] = $event;
    }

    #[EventHandler]
    public function object(AnEvent $event): void
    {
        $this->events[] = $event;
    }

    #[EventHandler('test.an_event')]
    public function named(array $event): void
    {
        $this->events[] = $event;
    }
}