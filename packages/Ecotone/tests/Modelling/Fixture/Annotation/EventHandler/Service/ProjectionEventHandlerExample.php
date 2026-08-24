<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Service;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\EventSourcing\Attribute\Projection;
use stdClass;

#[Projection('some', 'some')]
/**
 * licence Apache-2.0
 */
class ProjectionEventHandlerExample
{
    #[EventHandler(endpointId: 'eventHandler')]
    public function execute(stdClass $class): int
    {
    }
}
