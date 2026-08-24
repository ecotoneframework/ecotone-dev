<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithLimitedLoad;

use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ServiceContext;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\EventSourcing\Prooph\ProophProjectionRunningOption;
use Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList;

/**
 * licence Apache-2.0
 */
class ProjectionConfiguration
{
    #[ServiceContext]
    public function configureProjection()
    {
        return [
            EventSourcingConfiguration::createWithDefaults(),
            ProjectionRunningConfiguration::createEventDriven(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
                ->withTestingSetup()
                ->withOption(ProophProjectionRunningOption::OPTION_LOAD_COUNT, 2),
        ];
    }
}
