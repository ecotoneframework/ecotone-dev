<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;

/**
 * licence Apache-2.0
 */
class ProjectionConfiguration
{
    #[ServiceContext]
    public function configureProjection()
    {
        return [
            ProjectionRunningConfiguration::createEventDriven(InProgressTicketList::NAME)
                ->withTestingSetup(),
        ];
    }
}
