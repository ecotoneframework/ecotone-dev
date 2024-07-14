<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection;

use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

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
